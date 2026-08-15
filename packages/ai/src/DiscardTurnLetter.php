<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * Inbox control letter: drop a user turn from working conversation memory.
 *
 * Guidance: side-effect-only mail; it is a {@see Letter}, never a {@see Suspension\Continuation}.
 */
class DiscardTurnLetter implements Letter
{
    public function __construct(public string $turnId)
    {
    }

    public static function of(string $turnId): static
    {
        return new static($turnId);
    }
}
