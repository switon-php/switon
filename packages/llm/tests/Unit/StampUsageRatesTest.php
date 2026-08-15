<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Completion;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\StreamDelta;
use Switon\Ai\Thread;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Llm\AbstractLlm;
use Switon\Llm\Event\CompletionRequesting;
use Switon\Llm\Tests\TestCase;

#[CoversClass(AbstractLlm::class)]
class StampUsageRatesTest extends TestCase
{
    public function testAsReplyDispatchSkipsHitAndIdeal(): void
    {
        $prev = Completion::of(UserMessage::of('hi'), new AssistantMessage('ok'));
        $prev->usage = new TokenUsage(input: 15_000, output: 100, cacheRead: 10_000);

        $current = Completion::of(
            UserMessage::of('review'),
            new AssistantMessage('', [
                new ToolCall('c1', 'agent_code_review', ['as_reply' => true]),
            ]),
        );
        $current->usage = new TokenUsage(input: 16_068, output: 620, cacheRead: 0);

        $thread = $this->hang(new Thread('t1'), $prev);
        $this->hang($thread, $current);

        $this->stampUsageRates($thread, $current);

        $this->assertNull($current->usage->hit);
        $this->assertNull($current->usage->ideal);
        $this->assertSame(16_068, $current->usage->input);
    }

    public function testNormalToolCallStillStampsIdeal(): void
    {
        $prev = Completion::of(UserMessage::of('hi'), new AssistantMessage('ok'));
        $prev->usage = new TokenUsage(input: 15_000, output: 100, cacheRead: 10_000);

        $current = Completion::of(
            UserMessage::of('ls'),
            new AssistantMessage('', [
                new ToolCall('c1', 'bash', ['command' => 'ls']),
            ]),
        );
        $current->usage = new TokenUsage(input: 16_000, output: 40, cacheRead: 12_000);

        $thread = $this->hang(new Thread('t1'), $prev);
        $this->hang($thread, $current);

        $this->stampUsageRates($thread, $current);

        $this->assertSame(0.75, $current->usage->hit);
        $this->assertSame(0.938, $current->usage->ideal);
    }

    public function testToolResultBetweenRepliesStillUsesThePreviousAssistant(): void
    {
        $prev = Completion::of(UserMessage::of('hi'), new AssistantMessage('ok'));
        $prev->usage = new TokenUsage(input: 15_000, output: 100, cacheRead: 10_000);

        $tool = Completion::of(UserMessage::of('out'), ToolMessage::of([
            new ToolResult('listed', 'c0', 'bash'),
        ]));
        $current = Completion::of(UserMessage::of('next'), new AssistantMessage('done'));
        $current->usage = new TokenUsage(input: 16_000, output: 40, cacheRead: 12_000);

        $thread = $this->hang(new Thread('t1'), $prev);
        $this->hang($thread, $tool);
        $this->hang($thread, $current);

        $this->stampUsageRates($thread, $current);

        $this->assertSame(0.75, $current->usage->hit);
        $this->assertSame(0.938, $current->usage->ideal);
    }

    protected function stampUsageRates(Thread $thread, Completion $completion): void
    {
        $llm = new class () extends AbstractLlm {
            public function apply(Thread $thread, Completion $completion): void
            {
                $this->stampUsageRates($thread, $completion);
            }

            protected function wireComplete(CompletionRequesting $requesting): void
            {
            }

            protected function streamDeltaFromFrame(array $decoded): ?StreamDelta
            {
                return null;
            }
        };
        $llm->apply($thread, $completion);
    }
}
