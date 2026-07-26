<?php

declare(strict_types=1);

namespace Switon\Schedule\Event;

use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Throwable;

/**
 * A scheduled task invocation failed with an exception.
 *
 * Log category: `switon.schedule.task.failed`
 *
 * @see \Switon\Schedule\TaskRunner
 */
#[EventLevel(Severity::ERROR)]
class TaskFailed implements SenderAwareInterface
{
    /**
     * @param string $name Registered task id
     * @param Throwable $error Unhandled task failure
     */
    public function __construct(
        public \Switon\Schedule\TaskRunnerInterface $sender,
        public string    $name,
        public Throwable $error,
    ) {
    }

    public function getSender(): \Switon\Schedule\TaskRunnerInterface
    {
        return $this->sender;
    }
}
