<?php

declare(strict_types=1);

namespace Switon\Queue\Event;

use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Queue\QueueEnvelope;

/**
 * Event emitted when a dequeued message starts running.
 *
 * Log category: `switon.queue.consuming`
 *
 * @see \Switon\Queue\Queue::consume()
 * @see \Switon\Queue\Event\QueueConsumed
 * @see \Switon\Queue\Event\QueueFailed
 */
#[EventLevel(Severity::DEBUG)]
class QueueConsuming implements SenderAwareInterface
{
    /**
     * @param class-string<\Switon\Core\RunnerInterface> $jobClass
     */
    public function __construct(
        public \Switon\Queue\QueueInterface $sender,
        public string $queue,
        public QueueEnvelope $envelope,
        public string $jobClass,
    ) {
    }

    public function getSender(): \Switon\Queue\QueueInterface
    {
        return $this->sender;
    }
}
