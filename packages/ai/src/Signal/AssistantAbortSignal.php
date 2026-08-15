<?php

declare(strict_types=1);

namespace Switon\Ai\Signal;

use Switon\Ai\TurnStatus;

/**
 * Run ended with no reply: cancelled, failed, out of steps, or superseded. Label `abort.{kind}`.
 *
 * Guidance: exception/budget aborts map to {@see \Switon\Ai\TurnStatus::Aborted}, `KIND_CANCELLED` maps to
 * {@see \Switon\Ai\TurnStatus::Cancelled}, and `$kind` survives excerpting.
 */
class AssistantAbortSignal extends AssistantSignal
{
    /** Wire label prefix — `label()` appends `.{kind}`. */
    public const string NAME = 'assistant_abort';

    /** Someone called {@see \Switon\Ai\AgentInterface::cancel()} (user stop button, parent cancel). */
    public const string KIND_CANCELLED = 'cancelled';

    /** The run failed on an exception. */
    public const string KIND_EXCEPTION = 'exception';

    /** Step budget ran out before the agent finished. */
    public const string KIND_BUDGET_EXCEEDED = 'budget_exceeded';

    /** A new user message replaced this unfinished turn. */
    public const string KIND_SUPERSEDED = 'superseded';

    /**
     * @param string $kind Built-ins are the `KIND_*` constants; apps may add their own.
     * @param array<string, scalar|null> $fields
     */
    public function __construct(
        public string $kind = self::KIND_CANCELLED,
        array         $fields = [],
        string        $body = '',
    ) {
        parent::__construct(self::NAME, $fields, $body);
    }

    public function status(): TurnStatus
    {
        return $this->kind === self::KIND_CANCELLED ? TurnStatus::Cancelled : parent::status();
    }

    public function label(): string
    {
        return self::NAME . '.' . $this->kind;
    }
}
