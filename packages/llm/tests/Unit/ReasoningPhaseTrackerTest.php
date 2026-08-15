<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Ai\Completion;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\StreamDelta;
use Switon\Llm\ReasoningPhaseTracker;
use Switon\Testing\MockClock;

#[CoversClass(ReasoningPhaseTracker::class)]
class ReasoningPhaseTrackerTest extends TestCase
{
    public function testMeasuresReasoningUntilContentAppears(): void
    {
        $clock = new MockClock(100.0);
        $tracker = new ReasoningPhaseTracker($clock);
        $completion = Completion::of(UserMessage::of('Hi'));

        $tracker->observe(new StreamDelta(reasoning: 'Th'), $completion);
        $clock->advance(1.5);
        $tracker->observe(new StreamDelta(reasoning: 'ink'), $completion);
        $clock->advance(0.5);
        $tracker->observe(new StreamDelta(content: 'Hi'), $completion);

        $this->assertSame(2.0, $completion->timing?->reasoning);
    }

    public function testSameChunkWithReasoningAndContentClosesImmediately(): void
    {
        $clock = new MockClock(100.0);
        $tracker = new ReasoningPhaseTracker($clock);
        $completion = Completion::of(UserMessage::of('Hi'));

        $tracker->observe(new StreamDelta(reasoning: 'ink', content: 'Hi'), $completion);

        $this->assertNull($completion->timing);
    }

    public function testFinalizeWhenStreamEndsOnReasoningOnly(): void
    {
        $clock = new MockClock(100.0);
        $tracker = new ReasoningPhaseTracker($clock);
        $completion = Completion::of(UserMessage::of('Hi'));

        $tracker->observe(new StreamDelta(reasoning: 'Think.'), $completion);
        $clock->advance(3.25);
        $tracker->finalize($completion);

        $this->assertSame(3.25, $completion->timing?->reasoning);
    }

    public function testIgnoresChunksAfterReasoningClosed(): void
    {
        $clock = new MockClock(100.0);
        $tracker = new ReasoningPhaseTracker($clock);
        $completion = Completion::of(UserMessage::of('Hi'));

        $tracker->observe(new StreamDelta(reasoning: 'Th'), $completion);
        $clock->advance(1.0);
        $tracker->observe(new StreamDelta(content: 'Hi'), $completion);
        $clock->advance(5.0);
        $tracker->observe(new StreamDelta(reasoning: 'late'), $completion);

        $this->assertSame(1.0, $completion->timing?->reasoning);
    }
}
