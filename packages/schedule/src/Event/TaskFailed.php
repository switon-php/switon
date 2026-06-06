<?php

declare(strict_types=1);

namespace Switon\Schedule\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Throwable;

/**
 * A scheduled task invocation failed with an exception.
 *
 * Log category: schedule task lifecycle.
 *
 * @see \Switon\Schedule\TaskRunner
 */
#[EventLevel(Severity::ERROR)]
class TaskFailed
{
    /**
     * @param string $name Registered task id
     * @param Throwable $error Unhandled task failure
     */
    public function __construct(
        public string    $name,
        public Throwable $error,
    ) {
    }
}
