<?php

declare(strict_types=1);

namespace Switon\Ai\Event;

use JsonSerializable;
use Switon\Ai\AgentInterface;
use Switon\Ai\Suspension\Interrupt;
use Switon\Ai\Turn;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Open turn suspended on {@see Turn::$interrupt} — Loop emitted and returned; app owns continue / UI.
 *
 * Log category: `switon.ai.turn.suspended`
 *
 * @see \Switon\Agency\Agent
 */
#[EventLevel(Severity::NOTICE)]
class TurnSuspended implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public AgentInterface $sender,
        public Turn           $turn,
        public Interrupt      $interrupt,
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
            'turn_id' => $this->turn->turnId,
            'interrupt' => $this->interrupt::class,
            'agent' => $this->sender->parent() === null ? '' : $this->sender->profile()->id,
        ];
    }
}
