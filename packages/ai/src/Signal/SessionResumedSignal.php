<?php

declare(strict_types=1);

namespace Switon\Ai\Signal;

/**
 * Closure note when a restarted process seals an unfinished turn.
 *
 * Guidance: {@see \Switon\Agency\Agent::start()} emits this; a root turn paused on readonly tools
 * becomes {@see \Switon\Ai\Suspension\RetryInterrupt} instead.
 */
class SessionResumedSignal extends AssistantSignal
{
    public const string NAME = 'session_resumed';

    public function __construct(
        string $body = 'Session restarted. This unfinished turn was closed.',
    ) {
        parent::__construct(self::NAME, body: $body);
    }
}
