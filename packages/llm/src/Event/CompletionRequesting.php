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
 * Before a completion request is sent.
 *
 * Log category: `switon.llm.completion.requesting`
 *
 * Guidance: Listeners may mutate charter/thread/completion before the wire call.
 *
 * @see \Switon\Ai\LlmInterface::complete()
 * @see \Switon\Ai\LlmInterface::stream()
 * @see CompletionResponded
 */
#[EventLevel(Severity::DEBUG)]
class CompletionRequesting implements JsonSerializable, SenderAwareInterface
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
        $turn = $this->thread->lastTurn();

        return [
            'thread_id' => $this->thread->id,
            'turn_id' => $turn->turnId,
            'step' => $this->completion->step,
            'complete_id' => $this->completion->completeId,
            'model' => $turn->model,
            'stream' => $this->stream,
            'prompt_count' => trim($this->charter->prompt) !== '' ? 1 : 0,
            'tool_count' => count($this->charter->tools),
        ];
    }
}
