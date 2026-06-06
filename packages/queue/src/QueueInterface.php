<?php

declare(strict_types=1);

namespace Switon\Queue;

/**
 * Transport contract for logical queue messages.
 *
 * Guidance: queue-name validity is caller responsibility.
 *
 * Road-signs:
 * - push() for enqueue and delayed delivery
 * - pull() for one-shot dequeue
 * - migrateDue() for delayed-message promotion
 * - consume() for runner dispatch
 *
 * @see \Switon\Queue\Queue
 */
interface QueueInterface
{
    /**
     * Enqueue one message to a logical queue name.
     *
     * `queue` is the target logical queue name; `envelope` carries body, headers, and transport metadata.
     *
     * @param int $delay Delay in seconds; 0 means immediate enqueue.
     *
     * @return string Message id.
     */
    public function push(string $queue, QueueEnvelope $envelope, int $delay = 0): string;

    /**
     * Read one message from one queue or a queue list.
     *
     * @param string|list<string> $queues
     * @param float $timeout Timeout in seconds; 0 means wait indefinitely.
     *
     * @return QueueEnvelope|null Pulled message, or null when the timeout expires without a message
     */
    public function pull(string|array $queues, float $timeout = 0): ?QueueEnvelope;

    /**
     * Move due delayed messages into ready list.
     *
     * @param string|list<string> $queues
     * @param int $limit Max delayed members read per queue per scan loop.
     *
     * @return int Number of delayed messages promoted to ready lists
     */
    public function migrateDue(string|array $queues, int $limit = 100): int;

    /**
     * Read queue stats for CLI/reporting.
     *
     * @return array<string, mixed>
     */
    public function stats(string $queue): array;

    /**
     * Resolves the queue runner for an envelope and executes it.
     */
    public function consume(QueueEnvelope $envelope): void;
}
