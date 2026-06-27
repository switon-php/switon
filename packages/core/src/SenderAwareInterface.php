<?php

declare(strict_types=1);

namespace Switon\Core;

/**
 * Marker contract for event payloads that expose the dispatching component instance.
 *
 * Use for events that need instance-level routing or ownership tracking.
 *
 * Road-signs:
 * - implement getSender() to return the dispatching instance
 * - sender is recommended as the first constructor argument
 * - no sender, no instance-level routing
 *
 * @see \Switon\Eventing\EventDispatcherInterface
 */
interface SenderAwareInterface
{
    /**
     * Return the event sender.
     */
    public function getSender(): object;
}
