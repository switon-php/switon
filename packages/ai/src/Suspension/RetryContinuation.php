<?php

declare(strict_types=1);

namespace Switon\Ai\Suspension;

/**
 * Inbox mail: accept or reject a {@see RetryInterrupt}-suspended turn.
 *
 * Accept → reset hung tip and call LLM again.
 * Reject → settle the turn (no further drive).
 *
 * @see RetryInterrupt::resume()
 */
class RetryContinuation extends Continuation
{
    public const string DECISION_ACCEPT = 'accept';
    public const string DECISION_REJECT = 'reject';

    public function __construct(
        public string $decision = self::DECISION_ACCEPT,
    ) {
    }

    public static function of(string $decision = self::DECISION_ACCEPT): static
    {
        return new static($decision);
    }

    /** @return class-string<RetryInterrupt> */
    public function pairsWith(): string
    {
        return RetryInterrupt::class;
    }
}
