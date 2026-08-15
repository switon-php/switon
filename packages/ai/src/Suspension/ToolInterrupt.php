<?php

declare(strict_types=1);

namespace Switon\Ai\Suspension;

use Switon\Ai\ToolCall;

/**
 * Tool-related turn suspend. Resume writes one result into the tool-batch row opened before the tool loop.
 *
 * @see \Switon\Ai\Suspension\ToolReplyInterrupt
 * @see \Switon\Ai\Suspension\ToolReviewInterrupt
 */
abstract class ToolInterrupt extends Interrupt
{
    /**
     * @param array<string, mixed> $arguments Wire arguments at suspend time
     */
    public function __construct(
        public string $callId = '',
        public string $name = '',
        public array  $arguments = [],
    ) {
    }

    /** Suspend on a provider call, carrying its wire identity for the resume pair. */
    public static function of(ToolCall $call): static
    {
        return new static($call->id, $call->name, $call->arguments);
    }
}
