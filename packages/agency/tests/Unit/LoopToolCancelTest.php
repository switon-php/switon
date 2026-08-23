<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Agent;
use Switon\Agency\Loop;
use Switon\Agency\Tests\Fixtures\CancellableTool;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;

/**
 * Per-call cancellation inside one tool batch, with no child process and no sleeps.
 *
 * Cancelling a single tool call ends only that call: the rest of the batch still executes and the
 * turn still completes. Only a cancelled agent aborts the batch. The real-process counterpart lives
 * in {@see \Switon\Agency\Tests\Integration\CancelDuringBashIntegrationTest}.
 */
#[CoversClass(Loop::class)]
class LoopToolCancelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CancellableTool::reset();
    }

    public function testCancelledToolCallEndsOnlyThatCallAndBatchCompletes(): void
    {
        $cancelled = new ToolCall('c1', 'probe', ['.caption' => 'Held']);
        $cancelled->cancelled = true;

        $stub = new StubLlm();
        $completion = Completion::of(UserMessage::of(''), new AssistantMessage('', [
            $cancelled,
            new ToolCall('c2', 'probe', ['.caption' => 'Free']),
        ]));
        $completion->completeId = 'chatcmpl-tool-cancel-unit';
        $completion->finishReason = 'tool_calls';
        $completion->usage = new TokenUsage();
        $stub->responses[] = $completion;
        $stub->responses[] = StubLlm::assistant('done after tools');
        $this->container->set(LlmInterface::class, $stub);

        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => ['probe' => CancellableTool::class],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['probe']),
        ]);

        $turn = $this->runForTest($agent, UserMessage::of('go'));

        // The turn continued: two model steps happened, and the batch was not abandoned.
        $this->assertCount(2, $stub->requests, 'The turn must continue after a per-call cancel.');
        $this->assertSame('done after tools', (string) $turn?->lastCompletion()?->output?->content);

        // Both calls ran, so cancelling one call did not skip its sibling.
        $this->assertSame(['c1', 'c2'], CancellableTool::$executed);

        $toolMessages = array_values(array_filter(
            $this->threadMessages($agent),
            static fn (Message $message): bool => $message instanceof ToolMessage,
        ));
        $this->assertCount(1, $toolMessages);
        $this->assertCount(2, $toolMessages[0]->results, 'Both calls must land a result.');

        $results = $toolMessages[0]->results;
        $this->assertTrue($results[0]->content['cancelled'] ?? false);
        $this->assertFalse($results[1]->content['cancelled'] ?? true);
    }

    public function testCancelledAgentAbortsBatchBeforeNextCall(): void
    {
        $stub = new StubLlm();
        $completion = Completion::of(UserMessage::of(''), new AssistantMessage('', [
            new ToolCall('c1', 'probe', ['.caption' => 'First']),
            new ToolCall('c2', 'probe', ['.caption' => 'Second']),
        ]));
        $completion->completeId = 'chatcmpl-agent-cancel-unit';
        $completion->finishReason = 'tool_calls';
        $completion->usage = new TokenUsage();
        $stub->responses[] = $completion;
        $stub->responses[] = StubLlm::assistant('should not run');
        $this->container->set(LlmInterface::class, $stub);

        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => ['probe' => CancellableTool::class],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['probe']),
        ]);

        // Cancel the agent before the batch starts: the loop must stop without running tools.
        $agent->cancel();

        $this->assertCount(0, CancellableTool::$executed, 'A cancelled agent must not run tool calls.');
    }
}
