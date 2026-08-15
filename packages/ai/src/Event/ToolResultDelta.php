<?php

declare(strict_types=1);

namespace Switon\Ai\Event;

use JsonSerializable;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

use function strlen;

/**
 * One incremental tool-result fragment while a handler runs.
 *
 * Sender is the running tool, not the registry; identity is call id + wire name only.
 *
 * Log category: `switon.ai.tool.result.delta`
 */
#[EventLevel(Severity::DEBUG)]
class ToolResultDelta implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param non-empty-string $channel e.g. stdout, stderr, text
     */
    public function __construct(
        public ToolInterface    $sender,
        public string           $callId,
        public string           $name,
        public AgentInterface   $agent,
        public string           $chunk,
        public string           $channel = 'stdout',
    ) {
    }

    public function getSender(): ToolInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'thread_id' => $this->agent->threadId(),
            'name' => $this->name,
            'tool_call_id' => $this->callId,
            'channel' => $this->channel,
            'bytes' => strlen($this->chunk),
        ];
    }
}
