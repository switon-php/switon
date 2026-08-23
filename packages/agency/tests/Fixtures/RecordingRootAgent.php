<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Agency\Tests\Fixtures\UnitTestAgent;
use Switon\Agency\Thread\TaskFinishSignal;
use Switon\Ai\Envelope;
use Switon\Ai\Message;

/** Spies {@see deliver()} so unit tests need not wait for the parent worker. */
class RecordingRootAgent extends UnitTestAgent
{
    /** @var list<list<Message>> */
    public array $deliveries = [];

    public function deliver(Envelope $envelope): void
    {
        $this->deliveries[] = [$envelope->letter];
        parent::deliver($envelope);
    }

    /** @return list<array<string, mixed>> */
    public function deliveredTaskEvents(): array
    {
        $events = [];
        foreach ($this->deliveries as $batch) {
            foreach ($batch as $message) {
                if (!$message instanceof TaskFinishSignal) {
                    continue;
                }
                $events[] = $message->fields + [
                    'signal' => $message->name,
                    'body' => $message->body,
                ];
            }
        }

        return $events;
    }

    /** @return array<string, mixed>|null */
    public function lastDeliveredTaskEvent(?string $name = null): ?array
    {
        $events = $this->deliveredTaskEvents();
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if ($name === null || ($events[$i]['signal'] ?? null) === $name) {
                return $events[$i];
            }
        }

        return null;
    }
}
