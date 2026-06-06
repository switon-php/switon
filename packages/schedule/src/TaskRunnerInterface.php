<?php

declare(strict_types=1);

namespace Switon\Schedule;

/**
 * Executes scheduled runners (expression match or on-demand) with locking and executor dispatch.
 *
 * Road-signs:
 * - runTasks
 * - runTaskNow
 * - ScheduleCommand
 *
 * @see \Switon\Schedule\TaskRunner
 * @see \Switon\Schedule\Command\ScheduleCommand
 * @see \Switon\Schedule\LockInterface
 */
interface TaskRunnerInterface
{
    /** Runs all tasks whose schedule expression matches the current clock time. */
    public function runTasks(): void;

    /** Runs one task by registered name without evaluating the schedule expression; locking still applies. */
    public function runTaskNow(string $name): void;

    /** Whether the most recent run (runTasks/runTaskNow) observed at least one task failure. */
    public function hadFailures(): bool;
}
