<?php

declare(strict_types=1);

namespace Switon\Thread\Event;

use JsonSerializable;
use Switon\Ai\Thread;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Thread\ThreadsInterface;

use function count;
use function round;

/**
 * Emitted when one {@see \Switon\Ai\Turn} is sealed into thread storage
 * ({@see ThreadsInterface::commit()}): transcript jsonl/md, conversation jsonl,
 * open-file clear, and totals bump have all run.
 *
 * Guidance: This is the turn-level cost boundary — the last turn's {@see \Switon\Ai\Turn::$totals}.
 *
 * Log category: `switon.thread.turn.committed`
 *
 * @see \Switon\Thread\Event\CompletionAppended
 */
#[EventLevel(Severity::DEBUG)]
class TurnCommitted implements JsonSerializable, SenderAwareInterface
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
        $turn = $this->thread->lastTurn();

        return [
            'thread_id' => $this->thread->id,
            'turn_id' => $turn->turnId,
            'status' => $turn->status->value,
            'model' => $turn->model,
            'steps' => count($turn->completions),
            'totals' => $turn->totals,
            'opened_at' => $turn->openedAt,
            'closed_at' => $turn->closedAt,
            'wall' => $turn->openedAt > 0.0
                ? round($turn->closedAt - $turn->openedAt, 3)
                : 0.0,
        ];
    }
}
