<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Agent;
use Switon\Ai\AgentInterface;
use Switon\Agency\DeliverAck;
use Switon\Ai\InstructorInterface;
use Switon\Llm\Exception\ApiIncompleteException;
use Switon\Agency\Tests\Fixtures\BlockingTool;
use Switon\Agency\Tests\Fixtures\RecordingRootAgent;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Core\Json;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Suspension\ToolReplyInterrupt;
use RuntimeException;

/**
 * Background dispatch: {@see TaskDeliverTool} returns {@see \Switon\Agency\DeliverAck}; workers deliver via `task_finish`.
 */
#[CoversClass(Agent::class)]
#[Group('coroutine')]
class AsyncDelegationTest extends TestCase
{
    protected const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    protected const COMPOSITE_THREAD_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.t[1-9]\d{4,6}$/i';
    protected int $execCounter = 0;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => [
                'block' => BlockingTool::class,
                'echo' => StubTool::config('echo', 'Echo'),
                'suspend' => StubTool::config(
                    'suspend',
                    'Suspend the turn',
                    static fn (): ToolReplyInterrupt => new ToolReplyInterrupt('call_suspend', 'suspend'),
                    false,
                ),
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
    }

    protected function leadAgent(): RecordingRootAgent
    {
        $host = $this->make(RecordingRootAgent::class);
        $this->bindProfileOverride($host, $this->profileVariant($host->profile(), [
            'tools' => [...$host->profile()->tools, 'block'],
        ]));

        return $host;
    }

    /** @return array<string, mixed> */
    protected function exec(AgentInterface $lead, string $callId, string $message): array
    {
        $this->execCounter++;
        $arguments = [
            'agent' => 'lite',
            'message' => $message,
        ];

        $result = $this->invokeTool($lead, new ToolCall($callId, 'task_deliver', $arguments));
        $this->assertIsArray($result);

        return $result;
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    protected function callTool(AgentInterface $lead, string $callId, string $name, array $arguments): array
    {
        $result = $this->invokeTool($lead, new ToolCall($callId, $name, $arguments));
        $this->assertIsArray($result);

        return $result;
    }

    public function testBackgroundExecReturnsHandleAndDeliversViaTaskFinish(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('done');
        $stub->responses[] = StubLlm::assistant('parent resumed');
        $this->container->set(LlmInterface::class, $stub);

        $lead = $this->leadAgent();
        $block = $this->startBlockedRun($lead, $stub, 'hold parent');
        $leaf = '';
        try {
            $handle = $this->exec($lead, 'call_1', 'Work');

            $this->assertSame(DeliverAck::STATUS, $handle['status']);
            $this->assertSame(DeliverAck::MESSAGE, $handle['message']);
            $attrs = is_array($handle['payload'] ?? null) ? $handle['payload'] : [];
            $this->assertNotEmpty($attrs['task_id'] ?? null);
            $this->assertNotEmpty($attrs['thread_id'] ?? null);
            $leaf = (string) $attrs['thread_id'];
            $this->assertArrayNotHasKey('content', $handle);
            $this->assertArrayNotHasKey('note', $handle);
            $this->assertArrayNotHasKey('turn', $handle);
        } finally {
            $this->releaseBlockedRun($block);
        }

        // task_finish waits for parent idle (turn sealed + mailbox empty).
        $event = $this->awaitDeliveredTaskFinish($lead, $leaf);
        $this->assertNotNull($event);
        $this->assertSame('done', $event['body']);
        $this->assertSame($leaf, $event['thread_id']);
    }

    public function testDispatchToolsOmitAsyncFromSchema(): void
    {
        $schema = $this->container->get(ToolsInterface::class)->pick(['task_deliver'])['task_deliver'];
        $this->assertNotNull($schema);
        $this->assertArrayNotHasKey('async', $schema->definition['inputSchema']['properties']);
    }

    public function testMultipleAgentTasksDeliverTaskFinishesInOrder(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('r1');
        $stub->responses[] = $this->stubTaskFinish('r2');
        $stub->responses[] = StubLlm::assistant('parent resumed');
        // Parent model step for each flushed task_finish (empty stub → resumable, blocks further idle).
        $stub->responses[] = StubLlm::assistant('ack finish 1');
        $stub->responses[] = StubLlm::assistant('ack finish 2');
        $this->container->set(LlmInterface::class, $stub);

        $lead = $this->leadAgent();
        $block = $this->startBlockedRun($lead, $stub, 'hold parent');
        try {
            $first = $this->exec($lead, 'call_1', 'A');
            $this->assertSame(DeliverAck::STATUS, $first['status']);
            $second = $this->exec($lead, 'call_2', 'B');

            $this->assertSame(DeliverAck::STATUS, $second['status']);
        } finally {
            $this->releaseBlockedRun($block);
        }

        $this->pumpUntil(function () use ($lead): bool {
            $finishes = array_values(array_filter(
                $lead->deliveredTaskEvents(),
                static fn (array $e): bool => ($e['signal'] ?? '') === 'task_finish',
            ));

            return count($finishes) >= 2;
        });
        $events = array_values(array_filter(
            $lead->deliveredTaskEvents(),
            static fn (array $e): bool => ($e['signal'] ?? '') === 'task_finish',
        ));
        $this->assertCount(2, $events);
        $this->assertSame('r1', $events[0]['body']);
        $this->assertSame('r2', $events[1]['body']);
    }

    public function testFailedBackgroundDispatchReleasesChildWithTaskFinish(): void
    {
        $stub = new StubLlm();
        // Child lands echo then the next model step throws → task_finish failed and the worker stops.
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['value' => 'x']);
        $stub->responses[] = static function (mixed ...$args): never {
            throw new RuntimeException('stub LLM down');
        };
        $this->container->set(LlmInterface::class, $stub);

        $lead = $this->leadAgent();
        $block = $this->startBlockedRun($lead, $stub, 'hold parent');
        try {
            $handle = $this->invokeTool($lead, new ToolCall('call_1', 'task_deliver', [
                'agent' => 'lite',
                'message' => 'go',
            ]));
            $this->assertIsArray($handle);
            $this->assertSame(DeliverAck::STATUS, $handle['status'] ?? null);
            $this->assertSame(DeliverAck::MESSAGE, $handle['message'] ?? null);
            $attrs = is_array($handle['payload'] ?? null) ? $handle['payload'] : [];
            $this->assertNotEmpty($attrs['task_id'] ?? null);
            $this->assertNotEmpty($attrs['thread_id'] ?? null);

            $leaf = (string) $attrs['thread_id'];
            $this->pumpUntil(fn (): bool => $lead->lastDeliveredTaskEvent('task_finish') !== null);
            $finish = $lead->lastDeliveredTaskEvent('task_finish');
            $this->assertSame('failed', $finish['status'] ?? null);
            $this->assertSame($leaf, $finish['thread_id'] ?? null);
            $this->assertStringContainsString('stub LLM down', (string) ($finish['body'] ?? ''));
            $this->assertArrayNotHasKey($leaf, $this->liveChildren($lead));
        } finally {
            $this->releaseBlockedRun($block);
        }
    }

    public function testBackgroundSuspendFailsAndReleasesChild(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('suspend', callId: 'call_suspend');
        $this->container->set(LlmInterface::class, $stub);

        $profiles = $this->container->get(\Switon\Agency\ProfilesInterface::class);
        $this->setTestProfile($this->profileWithTools($profiles->get('lite'), 'suspend'));

        $lead = $this->leadAgent();
        $block = $this->startBlockedRun($lead, $stub, 'hold parent');
        try {
            $handle = $this->invokeTool($lead, new ToolCall('call_1', 'task_deliver', [
                'agent' => 'lite',
                'message' => 'ask',
            ]));
            $this->assertIsArray($handle);
            $attrs = is_array($handle['payload'] ?? null) ? $handle['payload'] : [];
            $leaf = (string) ($attrs['thread_id'] ?? '');

            $this->pumpUntil(fn (): bool => $lead->lastDeliveredTaskEvent('task_finish') !== null);
            $finish = $lead->lastDeliveredTaskEvent('task_finish');
            $this->assertSame('failed', $finish['status'] ?? null);
            $this->assertSame($leaf, $finish['thread_id'] ?? null);
            $this->assertSame(
                'Background task suspended on suspend; a child task cannot wait for the user.',
                $finish['body'] ?? null,
            );
            $this->assertArrayNotHasKey($leaf, $this->liveChildren($lead));
        } finally {
            $this->releaseBlockedRun($block);
        }
    }

    public function testBackgroundAgentTaskDeliversTaskFinishOnContinuationRun(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('task_deliver', [
            'agent' => 'lite',
            'message' => 'go',
        ]);
        $stub->responses[] = $this->stubRouteSubagentTaskFinishOr(
            'child-done',
            StubLlm::assistant('parent continues'),
        );
        $stub->responses[] = StubLlm::assistant('parent-ack');
        $this->container->set(LlmInterface::class, $stub);

        $lead = $this->leadAgent();
        $this->runForTest($lead, UserMessage::of('please delegate'));

        $event = $this->awaitDeliveredTaskFinish($lead);
        $this->assertNotNull($event, 'worker task_finish must reach parent inbox');
        $this->assertSame('child-done', $event['body']);

        $this->pumpUntil(
            fn (): bool => $this->lastAssistantContent($lead) === 'parent-ack',
            5.0,
        );
        $this->assertSame('parent-ack', $this->lastAssistantContent($lead));
    }

    public function testInstructRunRejectsTaskDeliver(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('task_deliver', [
            'agent' => 'lite',
            'message' => 'return the path',
        ]);
        $stub->responses[] = StubLlm::assistantWithToolCall('task_deliver', [
            'agent' => 'lite',
            'message' => 'return the path',
        ]);
        $this->container->set(LlmInterface::class, $stub);

        $lead = $this->container->make(InstructorInterface::class);

        try {
            $lead->ask(
                $this->testProfile(maxSteps: 2),
                'find file',
                ['path' => ['type' => 'string']],
            );
            $this->fail('expected ApiIncompleteException');
        } catch (ApiIncompleteException) {
            // Shared loop soft-rejects unknown tools (Host-style); without response the instruct stays open.
        }

        $found = false;
        foreach ($stub->requests as $request) {
            foreach ($request as $message) {
                if (!$message instanceof ToolMessage) {
                    continue;
                }
                foreach ($message->results as $result) {
                    $content = Json::text($result->wire());
                    if (str_contains($content, 'not allowed')) {
                        $found = true;
                        break 3;
                    }
                }
            }
        }
        $this->assertTrue($found);
    }

}
