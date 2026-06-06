<?php

declare(strict_types=1);

namespace Switon\Schedule;

/**
 * Discovers scheduled tasks and registers them with the schedule runtime.
 *
 * Use when boot-time scanning should populate the schedule registry from app
 * paths and package metadata.
 *
 * @see \Switon\Schedule\TaskDiscovery
 * @see \Switon\Schedule\ScheduleInterface
 */
interface TaskDiscoveryInterface
{
    /** Discovers and registers available scheduled task classes. */
    public function discover(): void;
}
