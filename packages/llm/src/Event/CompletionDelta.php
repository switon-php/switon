<?php

declare(strict_types=1);

namespace Switon\Llm\Event;

use JsonSerializable;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\StreamDelta;
use Switon\Ai\Thread;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * One {@see \Switon\Ai\StreamDelta} fragment landing on the in-progress `Completion` — the global
 * observability tap for LLM streaming; the caller-private channel is `$onDelta` on
 * {@see \Switon\Ai\LlmInterface::stream()}.
 *
 * Log category: `switon.llm.completion.delta`
 */
#[EventLevel(Severity::DEBUG)]
class CompletionDelta implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public LlmInterface $sender,
        public Thread $thread,
        public StreamDelta $streamDelta,
        public Completion $completion,
        public Charter $charter,
        public int $index = 0,
    ) {
    }

    public function getSender(): LlmInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'thread_id' => $this->thread->id,
            'complete_id' => $this->completion->completeId,
            'index' => $this->index,
            'response_id' => $this->streamDelta->id,
            'content_length' => strlen($this->streamDelta->content),
            'reasoning_length' => strlen($this->streamDelta->reasoning ?? ''),
            'finish_reason' => $this->streamDelta->finishReason,
            'at' => $this->streamDelta->at,
        ];
    }
}
