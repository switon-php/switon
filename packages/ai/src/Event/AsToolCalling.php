<?php

declare(strict_types=1);

namespace Switon\Ai\Event;

use JsonSerializable;
use Switon\Ai\AgentInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Sync as-tool worker spawned; host tool call still blocks until finish.
 *
 * Log category: `switon.ai.as.tool.calling`
 */
#[EventLevel(Severity::DEBUG)]
class AsToolCalling implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public AgentInterface $sender,
        public AgentInterface $worker,
        public string         $toolCallId,
        public string         $toolName,
        public string         $model = '',
    ) {
    }

    public function getSender(): AgentInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'thread_id' => $this->sender->threadId(),
            'tool_call_id' => $this->toolCallId,
            'tool_name' => $this->toolName,
            'agent' => $this->worker->profile()->id,
        ];
        if ($this->model !== '') {
            $data['model'] = $this->model;
        }

        return $data;
    }
}
