<?php

declare(strict_types=1);

namespace Switon\Schedule\Event;

use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * A scheduled task invocation completed without throwing.
 *
 * Log category: schedule task lifecycle.
 *
 * @see \Switon\Schedule\TaskRunner
 * @see \Switon\Schedule\Event\TaskFailed
 */
#[EventLevel(Severity::INFO)]
class TaskFinished implements SenderAwareInterface
{
    /**
     * @param string $name Registered task id
     * @param bool $immediate True when triggered by `schedule:task`, false for expression-matched runs
     */
    public function __construct(
        public \Switon\Schedule\TaskRunnerInterface $sender,
        public string $name,
        public bool   $immediate,
    ) {
    }

    public function getSender(): \Switon\Schedule\TaskRunnerInterface
    {
        return $this->sender;
    }
}
