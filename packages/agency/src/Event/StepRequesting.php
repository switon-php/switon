<?php

declare(strict_types=1);

namespace Switon\Agency\Event;

use JsonSerializable;
use Switon\Ai\AgentInterface;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Emitted by {@see \Switon\Agency\Loop} before a model step (validate / notify digest).
 *
 * Log category: `switon.agency.step.requesting`
 *
 * @see \Switon\Ai\Event\StepResponded
 */
#[EventLevel(Severity::DEBUG)]
class StepRequesting implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public AgentInterface $sender,
        public Completion $completion,
        public Charter $charter,
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
            'agent' => $this->sender->parent() === null ? '' : $this->sender->profile()->id,
            'turn_id' => $this->sender->thread()->lastTurn()->turnId,
            'step' => $this->completion->step,
            'tool_count' => count($this->charter->tools),
            'model' => $this->sender->thread()->lastTurn()->model,
        ];
    }
}
