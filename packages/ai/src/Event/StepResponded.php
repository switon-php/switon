<?php

declare(strict_types=1);

namespace Switon\Ai\Event;

use JsonSerializable;
use Switon\Ai\AgentInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

use function count;

/**
 * Emitted when one model step is agent-run-ready
 * (after validate / notify digest; before ordinary tool execution).
 *
 * Log category: `switon.ai.step.responded`
 */
#[EventLevel(Severity::DEBUG)]
class StepResponded implements JsonSerializable, SenderAwareInterface
{
    public function __construct(public AgentInterface $sender, public Completion $completion)
    {
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
            'step' => $this->completion->step,
            'complete_id' => $this->completion->completeId,
            'finish_reason' => $this->completion->finishReason,
            'tool_call_count' => $this->completion->output instanceof AssistantMessage
                ? count($this->completion->output->toolCalls)
                : 0,
            'timing' => $this->completion->timing?->jsonSerialize() ?? [],
        ];
    }
}
