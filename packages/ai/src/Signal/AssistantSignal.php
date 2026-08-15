<?php

declare(strict_types=1);

namespace Switon\Ai\Signal;

use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\TurnStatus;

/**
 * Assistant-side signal row; wire role {@see AssistantMessage::Role}.
 *
 * Guidance: host-side run ends land here ({@see AssistantAbortSignal},
 * {@see SessionResumedSignal}) — the model reads them as its own prior output, not as new input.
 */
class AssistantSignal extends \Switon\Ai\Signal
{
    public const string Role = AssistantMessage::Role;

    /** Turn status this signal establishes when it ends a turn. */
    public function status(): TurnStatus
    {
        return TurnStatus::Aborted;
    }
}
