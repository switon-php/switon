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
use Throwable;

/**
 * Completion failed before a successful response.
 *
 * Log category: `switon.llm.completion.failed`
 *
 * Guidance: `$httpStatus`/`$httpBody` set for non-success HTTP replies.
 *
 * @see \Switon\Ai\LlmInterface::complete()
 * @see \Switon\Ai\LlmInterface::stream()
 */
#[EventLevel(Severity::INFO)]
class CompletionFailed implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public LlmInterface $sender,
        public Thread $thread,
        public Completion $completion,
        public Throwable $error,
        public Charter $charter,
        public ?int $httpStatus = null,
        public ?string $httpBody = null,
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
        $turnId = '';
        $model = '';
        if ($this->thread->turns !== []) {
            $turn = $this->thread->lastTurn();
            $turnId = $turn->turnId;
            $model = $turn->model;
        }
        $data = [
            'thread_id' => $this->thread->id,
            'turn_id' => $turnId,
            'step' => $this->completion->step,
            'complete_id' => $this->completion->completeId,
            'model' => $model,
            'stream' => $this->stream,
            'error' => $this->error->getMessage(),
        ];

        if ($this->httpStatus !== null) {
            $data['http_status'] = $this->httpStatus;
        }

        return $data;
    }
}
