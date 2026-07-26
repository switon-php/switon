<?php

declare(strict_types=1);

namespace Switon\Queue\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Queue\QueueEnvelope;
use Throwable;

/**
 * Event emitted when a dequeued message fails during consume.
 *
 * Log category: `switon.queue.failed`
 *
 * @see \Switon\Queue\Queue::consume()
 * @see \Switon\Queue\Event\QueueConsuming
 */
#[EventLevel(Severity::ERROR)]
class QueueFailed implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param class-string<\Switon\Core\RunnerInterface> $jobClass
     */
    public function __construct(
        public \Switon\Queue\QueueInterface $sender,
        public string $queue,
        public QueueEnvelope $envelope,
        public string $jobClass,
        public string $exceptionMessage,
        public string $exceptionClass,
        public float $elapsed,
    ) {
    }

    /**
     * @param class-string<\Switon\Core\RunnerInterface> $jobClass
     */
    public static function from(
        \Switon\Queue\QueueInterface $sender,
        string $queue,
        QueueEnvelope $envelope,
        string $jobClass,
        Throwable $exception,
        float $elapsed,
    ): self {
        return new self(
            $sender,
            $queue,
            $envelope,
            $jobClass,
            $exception->getMessage(),
            $exception::class,
            $elapsed,
        );
    }

    public function getSender(): \Switon\Queue\QueueInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'queue' => $this->queue,
            'message_id' => $this->envelope->message_id,
            'jobClass' => $this->jobClass,
            'exceptionClass' => $this->exceptionClass,
            'exceptionMessage' => $this->exceptionMessage,
            'elapsed' => $this->elapsed,
        ];
    }
}
