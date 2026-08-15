<?php

declare(strict_types=1);

namespace Switon\Ai\Event;

use JsonSerializable;
use Switon\Ai\AgentInterface;
use Switon\Ai\Completion;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Emitted before one inbox delivery drive starts.
 *
 * Log category: `switon.ai.delivery.started`
 *
 * Carries this drive's tip {@see Completion} (`step` may be past 1). Serialized `agent`
 * is the sender profile id only when `parent()` is non-null.
 *
 * @see \Switon\Ai\Event\DeliverySettled
 */
#[EventLevel(Severity::DEBUG)]
class DeliveryStarted implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public AgentInterface $sender,
        public Completion     $completion,
    ) {
    }

    public function getSender(): AgentInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'thread_id' => $this->sender->threadId(),
            'turn_id' => $this->sender->thread()->lastTurn()->turnId,
            'model' => $this->sender->thread()->lastTurn()->model,
            'step' => $this->completion->step,
            'agent' => $this->sender->parent() !== null ? $this->sender->profile()->id : '',
        ];
    }
}
