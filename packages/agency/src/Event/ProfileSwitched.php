<?php

declare(strict_types=1);

namespace Switon\Agency\Event;

use JsonSerializable;
use Switon\Ai\AgentInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Host session profile changed via {@see \Switon\Agency\Tool\ProfileSwitchTool}
 * (or UI that applies the same switch).
 *
 * Apps may push a composer/profile wire event so the picker stays in sync.
 *
 * Log category: `switon.agency.profile.switched`
 */
#[EventLevel(Severity::DEBUG)]
class ProfileSwitched implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public AgentInterface $sender,
        public string         $prompt = '',
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
            'agent' => $this->sender->profile()->id,
            'prompt' => $this->prompt,
        ];
    }
}
