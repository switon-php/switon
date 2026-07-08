<?php

declare(strict_types=1);

namespace Switon\Executor\Event;

use JsonSerializable;
use Switon\Core\RunnerInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Executor\ExecutorInterface;

/**
 * Emitted immediately before executor calls <code>RunnerInterface::run()</code> once.
 *
 * Log category: <code>switon.executor.runner.starting</code>
 *
 * @see \Switon\Executor\Executor::invoke()
 * @see \Switon\Executor\Event\RunnerFinished
 */
#[EventLevel(Severity::DEBUG)]
class RunnerStarting implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public ExecutorInterface $sender,
        public RunnerInterface $runner,
        public string $task,
        public mixed $payload = null,
    ) {
    }

    public function getSender(): ExecutorInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'task' => $this->task,
        ];
    }
}
