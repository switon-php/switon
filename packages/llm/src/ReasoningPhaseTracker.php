<?php

declare(strict_types=1);

namespace Switon\Llm;

use Switon\Ai\Completion;
use Switon\Ai\LlmTiming;
use Switon\Ai\StreamDelta;
use Switon\Core\ClockInterface;

/** Wall time from first reasoning stream delta until content, tool calls, or stream end. */
class ReasoningPhaseTracker
{
    protected ?float $started = null;

    protected bool $closed = false;

    public function __construct(protected ClockInterface $clock)
    {
    }

    public function observe(StreamDelta $streamDelta, Completion $completion): void
    {
        if ($this->closed) {
            return;
        }

        if (($streamDelta->reasoning ?? '') !== '') {
            $this->started ??= $this->clock->microtime();
        }

        if ($streamDelta->content !== '' || $streamDelta->toolCallDeltas !== []) {
            $this->closeInto($completion);
        }
    }

    public function finalize(Completion $completion): void
    {
        if (!$this->closed && $this->started !== null) {
            $this->closeInto($completion);
        }
    }

    protected function closeInto(Completion $completion): void
    {
        if ($this->started === null) {
            return;
        }

        $reasoning = $this->clock->elapsed($this->started);
        if ($reasoning <= 0) {
            $this->closed = true;

            return;
        }

        $completion->timing ??= new LlmTiming();
        $completion->timing->reasoning = $reasoning;
        $this->closed = true;
    }
}
