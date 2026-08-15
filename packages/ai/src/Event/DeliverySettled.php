<?php

declare(strict_types=1);

namespace Switon\Ai\Event;

use JsonSerializable;
use Switon\Ai\AgentInterface;
use Switon\Ai\Turn;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Emitted when one inbox delivery drive settles.
 *
 * Log category: `switon.ai.delivery.settled`
 *
 * @see \Switon\Ai\Event\DeliveryStarted
 */
#[EventLevel(Severity::DEBUG)]
class DeliverySettled implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public AgentInterface $sender,
        public Turn           $turn,
    ) {
    }

    public function getSender(): AgentInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $delegated = $this->sender->parent() !== null;

        return [
            'thread_id' => $this->sender->threadId(),
            'turn_id' => $this->turn->turnId,
            'agent' => $delegated ? $this->sender->profile()->id : '',
        ];
    }
}
