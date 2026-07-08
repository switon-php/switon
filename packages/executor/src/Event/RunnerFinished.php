<?php

declare(strict_types=1);

namespace Switon\Executor\Event;

use JsonSerializable;
use Switon\Core\RunnerInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Executor\ExecutorInterface;
use Throwable;

/**
 * Emitted from <code>finally</code> after one <code>RunnerInterface::run()</code> call returns or throws.
 *
 * Log category: <code>switon.executor.runner.finished</code>
 *
 * @see \Switon\Executor\Executor::invoke()
 * @see \Switon\Executor\Event\RunnerStarting
 */
#[EventLevel(Severity::DEBUG)]
class RunnerFinished implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public ExecutorInterface $sender,
        public RunnerInterface $runner,
        public string $task,
        public mixed $payload = null,
        public mixed $result = null,
        public ?Throwable $error = null,
        public float $elapsed = 0.0,
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
            'result' => $this->result,
            'error' => $this->error === null ? null : [
                'class' => $this->error::class,
                'message' => $this->error->getMessage(),
                'code' => $this->error->getCode(),
                'file' => $this->error->getFile(),
                'line' => $this->error->getLine(),
            ],
            'elapsed' => $this->elapsed,
        ];
    }
}
