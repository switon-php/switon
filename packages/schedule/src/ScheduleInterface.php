<?php

declare(strict_types=1);

namespace Switon\Schedule;

/**
 * Registry of discovered and registered scheduled tasks.
 *
 * Use when discovery and execution need one canonical task table keyed by task id.
 *
 * Road-signs:
 * - register()
 * - feed from TaskDiscovery
 * - all()/get() for TaskRunner and CLI
 *
 * @see \Switon\Schedule\Schedule
 * @see \Switon\Schedule\TaskDiscoveryInterface
 * @see \Switon\Schedule\TaskRunner
 */
interface ScheduleInterface
{
    /**
     * @param class-string<\Switon\Core\RunnerInterface> $class
     */
    public function register(string $class): void;

    /**
     * @return array<string, ScheduledTask> name => task
     */
    public function all(): array;

    /**
     * Returns one registered task by name, or null when unknown.
     */
    public function get(string $name): ?ScheduledTask;
}
