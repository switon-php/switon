<?php

declare(strict_types=1);

namespace Switon\Queue\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Queue\QueueEnvelope;

/**
 * Event emitted when a message is enqueued.
 *
 * Log category: queue transport.
 *
 * @see \Switon\Queue\Queue::push()
 */
#[EventLevel(Severity::DEBUG)]
class QueuePushed
{
    /**
     * @param string $queue Logical queue name
     * @param QueueEnvelope $envelope Enqueued message envelope
     * @param int $delay Requested delay in seconds
     */
    public function __construct(
        public string        $queue,
        public QueueEnvelope $envelope,
        public int           $delay,
    ) {
    }
}
