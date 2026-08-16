<?php

declare(strict_types=1);

namespace Switon\Tooling\Event;

use JsonSerializable;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Tooling\ToolsInterface;

/**
 * Emitted by {@see ToolsInterface::call()} before the handler runs.
 *
 * Log category: `switon.tooling.tool.started`
 *
 * @see ToolFinished
 */
#[EventLevel(Severity::DEBUG)]
class ToolStarted implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public ToolsInterface $sender,
        public ToolCall $call,
        public AgentInterface $agent,
    ) {
    }

    public function getSender(): ToolsInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'thread_id' => $this->agent->threadId(),
            'name' => $this->call->name,
            'tool_call_id' => $this->call->id,
        ];
    }
}
