<?php

declare(strict_types=1);

namespace Switon\Schedule\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * A scheduled task invocation has started.
 *
 * Log category: schedule task lifecycle.
 *
 * @see \Switon\Schedule\TaskRunner
 * @see \Switon\Schedule\Event\TaskFinished
 */
#[EventLevel(Severity::INFO)]
class TaskStarted
{
    /**
     * @param string $name Registered task id
     * @param bool $immediate True when triggered by `schedule:task`, false for expression-matched runs
     */
    public function __construct(
        public string $name,
        public bool   $immediate,
    ) {
    }
}
