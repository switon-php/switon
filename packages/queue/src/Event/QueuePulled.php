<?php

declare(strict_types=1);

namespace Switon\Queue\Event;

use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Queue\QueueEnvelope;

/**
 * Event emitted when one pull attempt finishes.
 *
 * Log category: `switon.queue.pulled`
 *
 * @see \Switon\Queue\Queue::pull()
 */
#[EventLevel(Severity::DEBUG)]
class QueuePulled implements SenderAwareInterface
{
    /**
     * @param list<string> $queues Queues checked by the pull attempt
     * @param float $timeout Pull timeout in seconds
     * @param QueueEnvelope|null $envelope Pulled message, or null when nothing was available
     */
    public function __construct(
        public \Switon\Queue\QueueInterface $sender,
        public array          $queues,
        public float          $timeout,
        public ?QueueEnvelope $envelope,
    ) {
    }

    public function getSender(): \Switon\Queue\QueueInterface
    {
        return $this->sender;
    }
}
