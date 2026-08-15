<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Tests\TestCase;
use Switon\Core\Json;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\LlmTiming;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolResult;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\TokenUsage;

#[CoversClass(Completion::class)]
class CompletionTest extends TestCase
{
    public function testCancelledFinishReason(): void
    {
        $completion = Completion::of(UserMessage::of('Hi'));

        $completion->finishReason = Completion::REASON_CANCEL;

        $this->assertTrue($completion->isCancelled());
    }

    public function testMessagesReturnsInputThenOutputAndDropsNullSlots(): void
    {
        $input = UserMessage::of('Hi');
        $output = new AssistantMessage('Hello');

        $this->assertSame([$input, $output], Completion::of($input, $output)->messages());
        $this->assertSame([$input], Completion::of($input)->messages());
    }

    public function testProviderFinishReasonIsNotCancelled(): void
    {
        $completion = Completion::of(UserMessage::of('Hi'));
        $completion->finishReason = 'stop';

        $this->assertFalse($completion->isCancelled());
    }

    public function testEncodeDecodePreservesStep(): void
    {
        $completion = Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello'));
        $completion->completeId = 'corr-1';
        $completion->finishReason = 'stop';
        $completion->step = 3;

        $decoded = Completion::decode(Json::parse(Json::stringify($completion)));

        $this->assertSame(3, $decoded->step);
        $this->assertSame('corr-1', $decoded->completeId);
        $this->assertArrayHasKey('step', $completion->jsonSerialize());
        $this->assertArrayNotHasKey('turnId', $completion->jsonSerialize());
    }

    public function testDefaultStepRoundTrips(): void
    {
        $completion = Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello'));

        $this->assertSame(1, $completion->jsonSerialize()['step']);
        $this->assertSame(1, Completion::decode(Json::parse(Json::stringify($completion)))->step);
    }

    public function testNullOutputRoundTrips(): void
    {
        $completion = Completion::of(ToolMessage::of([new ToolResult('payload', 'call-1', 'echo')]));
        $completion->completeId = 'closure-1';

        $encoded = $completion->jsonSerialize();
        $this->assertNull($encoded['output']);

        $decoded = Completion::decode(Json::parse(Json::stringify($completion)));
        $this->assertNull($decoded->output);
        $this->assertSame('closure-1', $decoded->completeId);
    }

    public function testLandedAtRoundTrips(): void
    {
        $completion = Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello'));
        $completion->completeId = 'test-id';
        $completion->landedAt = 1_700_000_000.125;

        $encoded = $completion->jsonSerialize();
        $this->assertSame(1_700_000_000.125, $encoded['landedAt']);

        $decoded = Completion::decode(Json::parse(Json::stringify($completion)));
        $this->assertSame(1_700_000_000.125, $decoded->landedAt);
    }

    public function testDecodePreservesLandedAtPrecision(): void
    {
        $completion = Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello'));
        $completion->landedAt = 1_700_000_000.12349;

        $decoded = Completion::decode(Json::parse(Json::stringify($completion)));

        $this->assertSame(1_700_000_000.12349, $decoded->landedAt);
    }

    public function testDecodeSkipsUnknownKeysAndRestoresUsage(): void
    {
        $completion = Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello'));
        $completion->usage = new TokenUsage(3, 4);
        $bag = Json::parse(Json::stringify($completion));
        $bag['noise'] = 'x';

        $decoded = Completion::decode($bag);

        $this->assertSame(3, $decoded->usage?->input);
        $this->assertSame(4, $decoded->usage?->output);
    }

    public function testTimingRoundTrips(): void
    {
        $completion = Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello'));
        $completion->completeId = 'test-id';
        $completion->timing = LlmTiming::of(1.2345, 0.5, 0.25, 1_700_000_000.1234);

        $encoded = $completion->jsonSerialize();
        $this->assertSame(1.2345, $encoded['timing']?->wall);
        $this->assertSame(0.5, $encoded['timing']?->ttft);
        $this->assertSame(0.25, $encoded['timing']?->reasoning);
        $this->assertSame(1_700_000_000.1234, $encoded['timing']?->start);

        $decoded = Completion::decode(Json::parse(Json::stringify($completion)));
        $this->assertSame(1.2345, $decoded->timing?->wall);
        $this->assertSame(0.5, $decoded->timing?->ttft);
        $this->assertSame(0.25, $decoded->timing?->reasoning);
        $this->assertSame(1_700_000_000.1234, $decoded->timing?->start);
    }

    public function testReasoningTimingRoundTrips(): void
    {
        $completion = Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello', [], 'Think.'));
        $completion->completeId = 'test-id';
        $completion->timing = LlmTiming::of(3.5, 0.0, 1.2345);

        $encoded = $completion->jsonSerialize();
        $this->assertSame(1.2345, $encoded['timing']?->reasoning);

        $decoded = Completion::decode(Json::parse(Json::stringify($completion)));
        $this->assertSame(1.2345, $decoded->timing?->reasoning);
    }

    public function testOutputIsReplaceable(): void
    {
        $completion = Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello'));
        $completion->completeId = 'corr-1';
        $completion->finishReason = 'stop';
        $completion->step = 2;

        $completion->output = new AssistantMessage('');

        $this->assertSame('corr-1', $completion->completeId);
        $this->assertSame('stop', $completion->finishReason);
        $this->assertSame(2, $completion->step);
        $this->assertSame('', $completion->output->content);
    }

    public function testCloneIsolatesUsage(): void
    {
        $usage = new TokenUsage(10, 20);
        $completion = Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello'));
        $completion->completeId = 'test-id';
        $completion->usage = $usage;

        $copy = clone $completion;
        $copy->usage->input = 99;

        $this->assertSame(10, $completion->usage->input);
        $this->assertNotSame($completion->usage, $copy->usage);
    }

    public function testCloneIsolatesTiming(): void
    {
        $completion = Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello'));
        $completion->completeId = 'test-id';
        $completion->timing = LlmTiming::of(1.0, 0.2, 0.3);

        $copy = clone $completion;
        $copy->timing->wall = 9.0;

        $this->assertSame(1.0, $completion->timing->wall);
        $this->assertNotSame($completion->timing, $copy->timing);
    }

}
