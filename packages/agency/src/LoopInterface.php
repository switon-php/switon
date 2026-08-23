<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\AgentInterface;
use Throwable;

/**
 * Model step and tools drive over an open tip.
 *
 * Guidance: Reads agent cancel/charter and passes `onDelta` into the model stream; writes via {@see \Switon\Thread\ThreadsInterface}.
 * Success leaves the tip on the thread; failure returns {@see Throwable}.
 *
 * Road-signs:
 * - Step: {@see \Switon\Ai\LlmInterface::stream()} then tool dispatch through {@see \Switon\Tooling\ToolsInterface}
 * - Suspend: review/reply/retry raise {@see \Switon\Ai\Suspension\Interrupt}; resume lands a `Continuation`
 * - Stop: unsettled tools close before the turn seals
 *
 * @see \Switon\Agency\Loop
 * @see \Switon\Agency\Agent::executeDelivery()
 */
interface LoopInterface
{
    /** Tip stays on the thread. `$maxSteps` is that turn's model-step budget. Failure returns the throwable. */
    public function drive(AgentInterface $agent, Delivery $delivery, int $maxSteps): ?Throwable;
}
