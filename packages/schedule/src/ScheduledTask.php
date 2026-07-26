<?php

declare(strict_types=1);

namespace Switon\Schedule;

use Switon\Core\RunnerInterface;

/**
 * Registered schedulable task metadata.
 *
 * Use when passing normalized task identity, expression, class, and lock settings between registry, CLI, and runner.
 *
 * @see \Switon\Schedule\Schedule::register()
 * @see \Switon\Schedule\Attribute\Scheduled
 */
class ScheduledTask
{
    /**
     * @param class-string<RunnerInterface> $taskClass Task class FQCN.
     * @param int $lockTtl <code>0</code> when no distributed lock; otherwise Redis EX seconds.
     */
    public function __construct(
        public string $name,
        public string $taskClass,
        public string $expression,
        public int $lockTtl,
    ) {
    }
}
