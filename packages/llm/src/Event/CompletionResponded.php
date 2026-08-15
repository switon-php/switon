<?php

declare(strict_types=1);

namespace Switon\Llm\Event;

use JsonSerializable;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Thread;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * After a completion response.
 *
 * Log category: `switon.llm.completion.responded`
 *
 * Guidance: Listeners may mutate `$completion` before the caller reads it.
 *
 * @see \Switon\Ai\LlmInterface::complete()
 * @see \Switon\Ai\LlmInterface::stream()
 * @see CompletionRequesting
 */
#[EventLevel(Severity::DEBUG)]
class CompletionResponded implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public LlmInterface $sender,
        public Thread $thread,
        public Completion $completion,
        public Charter $charter,
        public bool $stream = false,
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
            'turn_id' => $this->thread->turns === [] ? '' : $this->thread->lastTurn()->turnId,
            'step' => $this->completion->step,
            'complete_id' => $this->completion->completeId,
            'content_length' => strlen($this->completion->output->content ?? ''),
            'reasoning_length' => strlen($this->completion->output->reasoning ?? ''),
            'finish_reason' => $this->completion->finishReason,
            'timing' => $this->completion->timing?->jsonSerialize() ?? [],
        ];
    }
}
