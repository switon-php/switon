<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Tool\ThreadForkTool;
use Switon\Agency\Tool\TaskDeliverTool;
use Switon\Agency\DeliverAck;
use Switon\Tooling\Event\ToolFailed;
use Switon\Agency\Tests\Fixtures\RecordingRootAgent;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\Exception\ToolInvalidArgumentException;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Ai\LlmInterface;
use Switon\Ai\Signal;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Agency\Agent;
use Switon\Agency\Exception;
use Switon\Agency\Tests\Fixtures\BlockingTool;
use Switon\Thread\Threads;
use Switon\Ai\Thread;

#[CoversClass(ThreadForkTool::class)]
#[CoversClass(TaskDeliverTool::class)]
#[Group('coroutine')]
class AgentLifecycleToolsTest extends TestCase
{
    protected const BORN_AT_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
    protected const RELATIVE_THREAD_PATTERN = '/^t[1-9]\d{4,}$/';

    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo'),
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
    }

    protected function leadAgent(): RecordingRootAgent
    {
        return $this->make(RecordingRootAgent::class, []);
    }

    protected const ASSIGN_NAME = 'lite-session';

    public function testTaskDeliverReturnsAckAndFinishes(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('done');
        $this->container->set(LlmInterface::class, $stub);

        $lead = $this->leadAgent();

        $payload = $this->invokeTool($lead, new ToolCall(
            'call_1',
            'task_deliver',
            [
                'agent' => 'lite',
                'message' => 'Work',
            ],
        ));

        $this->assertSame(DeliverAck::STATUS, $payload['status'] ?? null);
        $this->assertSame(DeliverAck::MESSAGE, $payload['message'] ?? null);
        $attrs = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $this->assertNotEmpty($attrs['task_id'] ?? null);
        $this->assertNotEmpty($attrs['thread_id'] ?? null);
        $this->assertMatchesRegularExpression(self::RELATIVE_THREAD_PATTERN, (string) $attrs['thread_id']);
        $this->assertArrayNotHasKey('content', $payload);
        $this->assertArrayNotHasKey('name', $payload);
        $this->pumpUntil(fn (): bool => $lead->lastDeliveredTaskEvent('task_finish') !== null);
        $finish = $lead->lastDeliveredTaskEvent('task_finish');
        $this->assertNotNull($finish);
        $this->assertSame('done', $finish['body']);
        $this->assertSame($attrs['thread_id'], $finish['thread_id'] ?? null);
        $this->assertSame($attrs['task_id'], $finish['task_id'] ?? null);
        $this->assertSame($attrs['thread_id'], $finish['thread_id'] ?? null);
        $this->assertSame([], $this->liveChildren($lead));
    }

    public function testTaskDeliverHonorsMaxStepsLimit(): void
    {
        $stub = new StubLlm();
        $stub->responses = [
            StubLlm::assistantWithToolCall('echo'),
            StubLlm::assistant('Work complete.'),
        ];
        $this->container->set(LlmInterface::class, $stub);

        $lead = $this->leadAgent();
        $result = $this->invokeTool($lead, new ToolCall(
            'call_1',
            'task_deliver',
            [
                'agent' => 'two-step',
                'message' => 'Work',
            ],
        ));

        $this->assertSame('delivered', $result['status']);
        $this->assertArrayNotHasKey('content', $result);
        $attrs = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $leaf = (string) ($attrs['thread_id'] ?? '');
        $this->pumpUntil(fn (): bool => $lead->lastDeliveredTaskEvent('task_finish') !== null);
        $finish = $lead->lastDeliveredTaskEvent('task_finish');
        $this->assertNotNull($finish);
        $this->assertSame('completed', $finish['status'] ?? null);
        $this->assertSame('Work complete.', $finish['body'] ?? null);
        $this->assertSame($leaf, $finish['thread_id'] ?? null);
    }

    public function testToolBindingFailureDispatchesToolFailed(): void
    {
        $before = count($this->eventDispatcher()->getEventsByClass(ToolFailed::class));
        $agent = $this->leadAgent();
        $call = new ToolCall('call_1', 'task_deliver', ['agent' => 'missing', 'message' => 'Work']);

        $this->invokeTool($agent, $call);

        $events = array_slice(
            $this->eventDispatcher()->getEventsByClass(ToolFailed::class),
            $before,
        );
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ToolInvalidArgumentException::class, $events[0]->result->content);
        $this->assertSame('agent value invalid', $events[0]->message());

        foreach ($this->threadCompletions($agent) as $completion) {
            if (!$completion->input instanceof ToolMessage) {
                continue;
            }
            $this->assertInstanceOf(ToolInvalidArgumentException::class, $completion->input->results[0]->content);

            return;
        }

        $this->fail('Expected persisted argument tool result.');
    }

    protected function eventDispatcher(): TestEventDispatcher
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);

        return $dispatcher;
    }

    public function testAssignAgentCreatesAndDeliversTask(): void
    {
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo'),
                'block' => BlockingTool::class,
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('block', ['key' => 'hold-assign'], 'b1', null);
        $stub->responses[] = $this->stubTaskFinish('done');
        $this->container->set(LlmInterface::class, $stub);

        $lead = $this->leadAgent();
        BlockingTool::arm('hold-assign');

        $payload = $this->invokeTool($lead, new ToolCall(
            'call_1',
            'task_deliver',
            [
                'agent' => 'lite',
                'message' => 'Work',
            ],
        ));

        $this->assertSame(DeliverAck::STATUS, $payload['status'] ?? null);
        $this->assertSame(DeliverAck::MESSAGE, $payload['message'] ?? null);
        $attrs = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $this->assertNotEmpty($attrs['task_id'] ?? null);
        $leaf = (string) ($attrs['thread_id'] ?? '');
        $this->assertMatchesRegularExpression(self::RELATIVE_THREAD_PATTERN, $leaf);
        $this->pumpUntil(fn (): bool => isset($this->liveChildren($lead)[$leaf]));
        BlockingTool::waitUntilBlocked('hold-assign');
        $peer = $this->requireSubagent($lead, $leaf);
        $this->assertSame($lead->threadId() . '.' . $leaf, $peer->threadId());
        BlockingTool::release('hold-assign');
        $this->pumpUntil(fn (): bool => $lead->lastDeliveredTaskEvent('task_finish') !== null);
        $this->assertSame([], $this->liveChildren($lead));
    }

    public function testParallelAssignSpawnsDistinctLeaves(): void
    {
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo'),
                'block' => BlockingTool::class,
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('block', ['key' => 'dup-child'], 'b1', null);
        $stub->responses[] = $this->stubTaskFinish('done');
        $this->container->set(LlmInterface::class, $stub);

        BlockingTool::arm('dup-child');

        $lead = $this->leadAgent();

        try {
            $firstCall = new ToolCall(
                'assign-1',
                'task_deliver',
                ['agent' => 'lite', 'message' => 'Work'],
            );
            $secondCall = new ToolCall(
                'assign-2',
                'task_deliver',
                ['agent' => 'lite', 'message' => 'More work'],
            );
            $first = $this->invokeTool($lead, $firstCall);
            $second = $this->invokeTool($lead, $secondCall);

            $this->assertSame(DeliverAck::STATUS, $first['status'] ?? null);
            $this->assertSame(DeliverAck::STATUS, $second['status'] ?? null);
            $firstLeaf = is_array($first['payload'] ?? null) ? ($first['payload']['thread_id'] ?? null) : null;
            $secondLeaf = is_array($second['payload'] ?? null) ? ($second['payload']['thread_id'] ?? null) : null;
            $this->assertMatchesRegularExpression(self::RELATIVE_THREAD_PATTERN, (string) $firstLeaf);
            $this->assertMatchesRegularExpression(self::RELATIVE_THREAD_PATTERN, (string) $secondLeaf);
            $this->assertNotSame($firstLeaf, $secondLeaf);
        } finally {
            BlockingTool::release('dup-child');
        }

        $this->pumpUntil(fn (): bool => $lead->lastDeliveredTaskEvent('task_finish') !== null);
    }

    public function testForkAgentBranchesHostAndDropsSubagents(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('done');
        $stub->responses[] = StubLlm::assistant('parent ack');
        $this->container->set(LlmInterface::class, $stub);

        $lead = $this->leadAgent();
        $this->threadAppend($lead->thread(), Completion::of(UserMessage::of('seed'), new AssistantMessage('ok')));
        $sub = $this->spawnDelegated($lead, 'lite');
        $this->openTaskForTest($sub, 'test-task');
        $this->deliverSubForTest($sub, UserMessage::of('Work'));

        $payload = $this->invokeTool($lead, new ToolCall(
            'call_3',
            'thread_fork',
            [],
        ));

        $this->assertArrayHasKey('thread_id', $payload);
        $this->assertMatchesRegularExpression(self::BORN_AT_PATTERN, $payload['born_at']);
        $this->assertNotSame($lead->threadId(), $payload['thread_id']);

        $fork = $this->startRoot('@threads/' . $payload['thread_id']);
        $this->assertInstanceOf(Agent::class, $fork);
        $this->assertSame([], $this->liveChildren($fork));
        $this->assertSame([], $this->liveChildren($lead));
    }

    public function testForkCopiesHistoryIntoNewThread(): void
    {
        $lead = $this->leadAgent();
        $this->threadAppend($lead->thread(), Completion::of(UserMessage::of('hello'), new AssistantMessage('hi')));

        $payload = $this->invokeTool($lead, new ToolCall('call-fork', 'thread_fork', []));
        $fork = $this->startRoot('@threads/' . $payload['thread_id']);

        $this->assertNotSame($lead->threadId(), $fork->threadId());
        $this->assertSame(
            'hello',
            $this->flattenWireMessages($this->loadThread($fork->thread()->datadir, $fork->thread()->id)->completions())[0]->wire()[0]['text'],
        );
    }

    /** Fork is a byte copy: it must not append ledger/signal rows of its own. */
    public function testForkCopiesTranscriptWithoutInjectingSignals(): void
    {
        $lead = $this->leadAgent();
        $this->threadAppend($lead->thread(), Completion::of(UserMessage::of('seed'), new AssistantMessage('ok')));
        $before = count($lead->thread()->completions());

        $payload = $this->invokeTool($lead, new ToolCall('call-fork', 'thread_fork', []));

        // Read the copy before starting it: `start()` resumes an open turn and appends a signal.
        $copied = $this->loadThread('@threads/' . $payload['thread_id'])->completions();
        $this->assertCount($before, $copied);
        foreach ($copied as $row) {
            $this->assertNotInstanceOf(Signal::class, $row->input);
            $this->assertNotInstanceOf(Signal::class, $row->output);
        }

        $fork = $this->startRoot('@threads/' . $payload['thread_id']);
        $this->assertArrayNotHasKey('subagents', $this->loadThread($fork->thread()->datadir, $fork->thread()->id)->state);
    }

    public function testForkAgentRejectsWhenInvokedConceptuallyOnSubPath(): void
    {
        $lead = $this->leadAgent();
        $task = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $lead,
        ]);
        $this->registerChildForTest($lead, $task);

        $this->expectException(Exception::class);
        $this->container->get(ThreadForkTool::class)->call($task, new ToolCall('call-fork', 'thread_fork', []), []);
    }
}
