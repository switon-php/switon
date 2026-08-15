<?php

declare(strict_types=1);

namespace Switon\Ai\Suspension;

/**
 * Inbox mail: review decision on a pending tool call.
 *
 * Allow → clear suspend; Loop continues the tool batch (host + invoke).
 * Deny → land a denied tool-result tip when the turn resumes.
 *
 * @see ToolReviewInterrupt
 */
class ToolReviewContinuation extends Continuation
{
    public const string DECISION_ALLOW = 'allow';
    public const string DECISION_DENY = 'deny';

    public function __construct(
        public string $callId,
        public string $decision = self::DECISION_ALLOW,
        public string $reason = '',
    ) {
    }

    public static function allow(string $callId): static
    {
        return new static($callId, self::DECISION_ALLOW);
    }

    public static function deny(string $callId, string $reason = ''): static
    {
        return new static($callId, self::DECISION_DENY, $reason);
    }

    /** @return class-string<ToolReviewInterrupt> */
    public function pairsWith(): string
    {
        return ToolReviewInterrupt::class;
    }
}
