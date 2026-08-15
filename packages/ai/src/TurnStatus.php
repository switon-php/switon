<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * Lifecycle state of one {@see Turn}: running → suspended → completed | cancelled | aborted.
 *
 * `settled()` splits open states from terminal states. `cancelled` means a deliberate stop;
 * `aborted` covers exception, budget exhaustion, or supersession with reason in
 * {@see \Switon\Ai\Signal\AssistantAbortSignal::$kind}.
 * Guidance: only `suspended` carries {@see Turn::$interrupt}.
 * Persisted as the lowercase case name.
 */
enum TurnStatus: string
{
    case Running = 'running';
    case Suspended = 'suspended';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Aborted = 'aborted';

    public function settled(): bool
    {
        return $this !== self::Running && $this !== self::Suspended;
    }
}
