<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Agent;
use Switon\Agency\Exception\RunCancelledException;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Ai\TurnStatus;
use Switon\Tooling\Tool\BashTool;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;

/**
 * Cancellation against a real child process.
 *
 * These tests spawn `bash` and stop it mid-flight, so they belong to the integration suite:
 * scheduling and process I/O are part of what they assert. The batch/dispatch logic they
 * exercise is covered deterministically in {@see \Switon\Agency\Tests\Unit\LoopTest}.
 */
#[CoversClass(Agent::class)]
class CancelDuringBashIntegrationTest extends TestCase
{
    public function testCancelDuringBashKeepsAskAndClosesTools(): void
    {
        $stub = new StubLlm();
        /** @var Agent|null $agentRef */
        $agentRef = null;
        $completion2 = Completion::of(UserMessage::of(''), new AssistantMessage('', [
            new ToolCall('c1', 'bash', [
                'command' => 'echo partial; sleep 30',
                '.caption' => 'Shell',
            ]),
        ]));
        $completion2->completeId = 'chatcmpl-bash-cancel';
        $completion2->finishReason = 'tool_calls';
        $completion2->usage = new TokenUsage();
        $stub->responses[] = $completion2;
        $stub->responses[] = StubLlm::assistant('should not continue');
        $this->container->set(LlmInterface::class, $stub);

        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => ['bash' => BashTool::class],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['bash']),
        ]);
        $agentRef = $agent;

        $cid = \Swoole\Coroutine::create(static function () use (&$agentRef): void {
            \Swoole\Coroutine::sleep(0.2);
            $agentRef?->cancel();
        });

        try {
            $error = $this->tryRun($agent, UserMessage::of('go'));
        } finally {
            if ($cid !== false) {
                \Swoole\Coroutine::cancel($cid);
            }
        }

        $this->assertNotNull($error);
        $this->assertInstanceOf(RunCancelledException::class, $error);
        $this->assertCount(1, $stub->requests);

        $stop = $this->interruptCompletion($agent, 'cancelled');
        $this->assertNotNull($stop);
        $this->assertInstanceOf(AssistantAbortSignal::class, $stop->output);
        $this->assertSame(AssistantAbortSignal::KIND_CANCELLED, $stop->output->kind);
        $this->assertSame(TurnStatus::Cancelled, $agent->thread()->lastTurn()->status);
        $this->assertContains('go', array_map(
            static fn (Message $message): string => $message instanceof UserMessage ? (string) $message->text() : '',
            $this->threadMessages($agent),
        ));
        $this->assertNotEmpty(array_filter(
            $this->threadMessages($agent),
            static fn (Message $message): bool => $message instanceof ToolMessage,
        ));
    }

    public function testCancelToolDuringBashContinuesBatchAndRun(): void
    {
        $stub = new StubLlm();
        /** @var Agent|null $agentRef */
        $agentRef = null;
        $completion = Completion::of(UserMessage::of(''), new AssistantMessage('', [
            new ToolCall('c1', 'bash', [
                'command' => 'echo partial; sleep 30',
                '.caption' => 'Shell',
            ]),
            new ToolCall('c2', 'bash', [
                'command' => 'echo second',
                '.caption' => 'Shell',
            ]),
        ]));
        $completion->completeId = 'chatcmpl-tool-cancel';
        $completion->finishReason = 'tool_calls';
        $completion->usage = new TokenUsage();
        $stub->responses[] = $completion;
        $stub->responses[] = StubLlm::assistant('done after tools');
        $this->container->set(LlmInterface::class, $stub);

        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => ['bash' => BashTool::class],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['bash']),
        ]);
        $agentRef = $agent;

        $cid = \Swoole\Coroutine::create(static function () use (&$agentRef): void {
            \Swoole\Coroutine::sleep(0.2);
            if ($agentRef !== null) {
                $agentRef->thread()->lastTurn()->toolCall('c1')->cancelled = true;
            }
        });

        try {
            $turn = $this->runForTest($agent, UserMessage::of('go'));
        } finally {
            if ($cid !== false) {
                \Swoole\Coroutine::cancel($cid);
            }
        }

        $this->assertSame('done after tools', (string) $turn->lastCompletion()?->output?->content);
        $this->assertCount(2, $stub->requests);

        $toolMessages = array_values(array_filter(
            $this->threadMessages($agent),
            static fn (Message $message): bool => $message instanceof ToolMessage,
        ));
        $this->assertCount(1, $toolMessages);
        $this->assertCount(2, $toolMessages[0]->results);

        $first = $toolMessages[0]->results[0]->content;
        $this->assertIsArray($first);
        $this->assertTrue($first['cancelled'] ?? false);
        $this->assertStringContainsString('partial', (string) ($first['stdout'] ?? ''));
        $second = $toolMessages[0]->results[1]->content;
        $this->assertIsArray($second);
        $this->assertStringContainsString('second', (string) ($second['stdout'] ?? ''));
        $this->assertNull($this->interruptCompletion($agent, 'cancelled'));
    }
}
