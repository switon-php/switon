<?php

declare(strict_types=1);

namespace Switon\Queue;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\Json;
use Switon\Core\RandomInterface;
use Switon\Core\Runtime;
use Switon\Executor\ExecutorInterface;
use Switon\Queue\Event\QueueConsumed;
use Switon\Queue\Event\QueueConsuming;
use Switon\Queue\Event\QueueFailed;
use Switon\Queue\Event\QueuePulled;
use Switon\Queue\Event\QueuePushed;
use Switon\Redis\ClientInterface;
use Throwable;

use function is_array;
use function is_string;

/**
 * Default Redis-backed queue transport (push/pull/consume/stats).
 *
 * Guidance: API arguments and <code>QueueEnvelope::$queue</code> are logical queue names.
 * Queue names starting with `.` skip stats tracking.
 *
 * @see \Switon\Queue\QueueInterface
 * @see \Switon\Queue\JobDiscoveryInterface
 */
class Queue implements QueueInterface
{
    protected const int STATS_TTL = 2592000;

    #[Autowired] protected ClockInterface $clock;
    #[Autowired] protected ExecutorInterface $executor;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected JobDiscoveryInterface $jobDiscovery;
    #[Autowired] protected ClientInterface $redisQueue;
    #[Autowired] protected RandomInterface $random;

    #[Autowired] protected string $prefix = 'queue:';

    /**
     * Minimum seconds between `migrateDue` runs for a logical queue in this PHP process.
     */
    #[Autowired] protected int $dueEvery = 1;

    /** @var array<string, int> logical queue name => last unix time `migrateDue` ran */
    protected array $dueAt = [];

    public function push(string $queue, QueueEnvelope $envelope, int $delay = 0): string
    {
        if ($envelope->message_id === '') {
            $envelope->message_id = $this->random->uuid();
        }
        $envelope->queue = $queue;
        if ($envelope->timestamp <= 0) {
            $envelope->timestamp = $this->clock->time();
        }

        $this->bumpStat($queue, 'pushed');

        $payload = Json::stringify($envelope);
        if ($delay > 0) {
            $this->redisQueue->zAdd($this->key('delayed', $queue), $this->clock->time() + $delay, $payload);
            $this->bumpStat($queue, 'delayed');
        } else {
            $this->redisQueue->lPush($this->key('ready', $queue), $payload);
        }
        $this->eventDispatcher->dispatch(new QueuePushed($this, $queue, $envelope, $delay));

        return $envelope->message_id;
    }

    public function stats(string $queue): array
    {
        $ready = (int)$this->redisQueue->lLen($this->key('ready', $queue));
        $delayed = (int)$this->redisQueue->zCard($this->key('delayed', $queue));
        $failed = (int)$this->redisQueue->lLen($this->key('failed', $queue));
        $counter = $queue[0] === '.' ? [] : (array)$this->redisQueue->hGetAll($this->key('stats', $queue));

        return [
            'queue' => $queue,
            'prefix' => $this->prefix,
            'ready' => $ready,
            'delayed' => $delayed,
            'failed' => $failed,
            'counter' => $counter,
        ];
    }

    public function pull(string|array $queues, float $timeout = 0): ?QueueEnvelope
    {
        $names = is_string($queues) ? [$queues] : $queues;
        if ($names === []) {
            $names = $this->jobDiscovery->queues();
        }
        if ($names === []) {
            return null;
        }

        $readyPrefix = $this->key('ready', '');
        $keys = [];
        foreach ($names as $candidate) {
            $keys[] = $readyPrefix . $candidate;
        }
        $row = $this->redisQueue->brPop($keys, $timeout);
        if (!is_array($row) || $row === []) {
            $this->eventDispatcher->dispatch(new QueuePulled($this, $names, $timeout, null));
            return null;
        }

        $payload = (string)($row[1] ?? '');

        $decoded = Json::parse($payload);
        $envelope = QueueEnvelope::of();
        foreach ($decoded as $field => $value) {
            $envelope->{$field} = $value;
        }
        $this->eventDispatcher->dispatch(new QueuePulled($this, $names, $timeout, $envelope));

        return $envelope;
    }

    public function migrateDue(string|array $queues, int $limit = 100): int
    {
        $names = is_string($queues) ? [$queues] : $queues;
        if ($names === []) {
            return 0;
        }

        $moved = 0;
        foreach ($names as $queue) {
            if (!is_string($queue) || $queue === '') {
                continue;
            }

            $moved += $this->maybeMigrateDue($queue, $limit);
        }

        return $moved;
    }

    public function consume(QueueEnvelope $envelope): void
    {
        $queue = $envelope->queue;
        $definition = $this->jobDiscovery->all()[$queue] ?? null;
        $jobClass = $definition['class'] ?? null;
        $concurrency = $definition['concurrency'] ?? 1;
        if ($jobClass === null || $jobClass === '') {
            Exception::raise(
                'Queue "{queue}" is not mapped to a runner (#[Queued] scan or switon.jobs).',
                ['queue' => $queue]
            );
        }

        $this->eventDispatcher->dispatch(new QueueConsuming($this, $queue, $envelope, $jobClass));

        $startedAt = $this->clock->microtime();
        try {
            if ($concurrency <= 1) {
                $this->executor->invoke($jobClass, $envelope);
            } else {
                $this->executor->submit($jobClass, $envelope, $concurrency);
            }
        } catch (Throwable $exception) {
            $this->eventDispatcher->dispatch(QueueFailed::from($this, $queue, $envelope, $jobClass, $exception, $this->clock->elapsed($startedAt)));
            throw $exception;
        }

        if ($concurrency <= 1 || !Runtime::isCoroutineEnabled()) {
            $this->eventDispatcher->dispatch(new QueueConsumed($this, $queue, $envelope, $jobClass, $this->clock->elapsed($startedAt)));
        }
    }

    /**
     * Move due delayed messages to ready list for one logical queue.
     *
     * Flow:
     * - Called by `migrateDue` from delay migrator loop.
     * - Throttled per queue by `$dueEvery`; if interval not reached, return immediately.
     * - Once triggered, drain currently due delayed messages in batches (100 each) until empty.
     */
    protected function maybeMigrateDue(string $queue, int $limit = 100): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $now = $this->clock->time();
        if ($now - ($this->dueAt[$queue] ?? 0) < $this->dueEvery) {
            return 0;
        }

        $this->dueAt[$queue] = $now;
        $moved = 0;
        while (true) {
            $delayed = $this->redisQueue->zRangeByScore(
                $this->key('delayed', $queue),
                "0",
                (string)$now,
                ['limit' => [0, $limit]]
            );
            if (!is_array($delayed) || $delayed === []) {
                return $moved;
            }

            foreach ($delayed as $payload) {
                if (!is_string($payload)) {
                    continue;
                }

                if ($this->redisQueue->zRem($this->key('delayed', $queue), $payload) <= 0) {
                    continue;
                }
                $this->redisQueue->lPush($this->key('ready', $queue), $payload);
                $moved++;
            }
        }
    }

    protected function bumpStat(string $queue, string $field, int $value = 1): void
    {
        if ($queue !== '' && $queue[0] === '.') {
            return;
        }

        $key = $this->key('stats', $queue);
        $this->redisQueue->hIncrBy($key, $field, $value);
        if (static::STATS_TTL > 0) {
            $this->redisQueue->expire($key, static::STATS_TTL);
        }
    }

    protected function key(string $segment, string $queue): string
    {
        return $this->prefix . $segment . ':' . $queue;
    }
}
