<?php

declare(strict_types=1);

namespace Switon\Thread\Event;

use JsonSerializable;
use Switon\Ai\Thread;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Thread\ThreadsInterface;

/**
 * A completion has landed on the open tip and been written with {@see ThreadsInterface::saveTurn()}.
 *
 * Guidance: the caller that landed the completion dispatches this; `saveTurn()` itself does not.
 *
 * Log category: `switon.thread.completion.appended`
 */
#[EventLevel(Severity::DEBUG)]
class CompletionAppended implements JsonSerializable, SenderAwareInterface
{
    public function __construct(public ThreadsInterface $sender, public Thread $thread)
    {
    }

    public function getSender(): ThreadsInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $completion = $this->thread->lastCompletion();

        return [
            'thread_id' => $this->thread->id,
            'step' => $completion->step,
            'complete_id' => $completion->completeId,
            'has_output' => $completion->output !== null,
        ];
    }
}
