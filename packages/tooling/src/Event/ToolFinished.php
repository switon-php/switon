<?php

declare(strict_types=1);

namespace Switon\Tooling\Event;

use JsonSerializable;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Tooling\ToolsInterface;

/**
 * Emitted by {@see ToolsInterface::call()} after the handler returns a result, including a wrapped exception.
 *
 * Guidance: not emitted when the handler returns {@see \Switon\Ai\Suspension\Interrupt}.
 *
 * Log category: `switon.tooling.tool.finished`
 *
 * @see ToolStarted
 */
#[EventLevel(Severity::DEBUG)]
class ToolFinished implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public ToolsInterface $sender,
        public ToolCall $call,
        public AgentInterface $agent,
        public ToolResult $result,
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
            'timing' => $this->result->timing?->jsonSerialize(),
        ];
    }
}
