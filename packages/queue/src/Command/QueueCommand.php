<?php

declare(strict_types=1);

namespace Switon\Queue\Command;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Core\Json;
use Switon\Core\RandomInterface;
use Switon\Queue\JobDiscoveryInterface;
use Switon\Queue\QueueEnvelope;
use Switon\Queue\QueueInterface;
use Switon\Redis\ClientInterface;
use Throwable;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function is_array;
use function is_string;
use function microtime;
use function usleep;

/**
 * CLI entrypoints for `queue:stats`, `queue:push`, `queue:run`, `queue:work`, and `queue:migrate-due`.
 *
 * Use when operating queue transport state, one-shot consumers, or long-running workers from the console.
 *
 * Road-signs:
 * - statsAction() for queue counters
 * - pushAction() for ad hoc enqueue
 * - runAction() for one-shot consume
 * - workAction() for continuous worker loop
 * - migrateDueAction() for delayed queue promotion leader loop
 */
class QueueCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected QueueInterface $queue;
    #[Autowired] protected JobDiscoveryInterface $jobDiscovery;
    #[Autowired] protected ClientInterface $redisQueue;
    #[Autowired] protected RandomInterface $random;

    /** Shows queue stats; default command target. */
    public function defaultAction(string $queue = '', bool $json = true): int
    {
        return $this->statsAction($queue, $json);
    }

    /** Shows queue stats as JSON or table output. */
    public function statsAction(string $queue = '', bool $json = true): int
    {
        $stats = $this->queue->stats($queue);
        if ($json) {
            $this->console->writeLn(Json::stringify($stats));
            return 0;
        }

        $this->console->table(
            ['queue', 'ready', 'delayed', 'failed'],
            [[
                $stats['queue'] ?? '',
                $stats['ready'] ?? 0,
                $stats['delayed'] ?? 0,
                $stats['failed'] ?? 0,
            ]]
        );

        return 0;
    }

    /** Pushes one JSON payload to a logical queue. */
    public function pushAction(string $queue = '', string $body = '{}', int $delay = 0): int
    {
        try {
            $payload = Json::parse($body);
            if (!is_array($payload)) {
                return $this->console->error('Body must be a JSON object.');
            }
            $id = $this->queue->push($queue, QueueEnvelope::of($payload), $delay);
            $this->console->writeLn('queued: ' . $id);
            return 0;
        } catch (Throwable $e) {
            return $this->console->error('Push failed: {message}', ['message' => $e->getMessage()]);
        }
    }

    /** Pulls and consumes at most one message. */
    public function runAction(string $queue = ''): int
    {
        $names = $this->parseQueueNames($queue);
        if ($names === []) {
            $names = $this->jobDiscovery->queues();
        }
        if ($names === []) {
            return $this->console->error('No queues discovered.');
        }

        try {
            $target = count($names) === 1 ? $names[0] : $names;
            $envelope = $this->queue->pull($target, 1);
            if ($envelope !== null) {
                $this->queue->consume($envelope);
            }

            return 0;
        } catch (Throwable $e) {
            return $this->console->error('Run failed: {message}', ['message' => $e->getMessage()]);
        }
    }

    /** Runs a continuous worker loop for one or more queues. */
    public function workAction(string $queue = '', int $timeout = 0, int $sleepMs = 200): int
    {
        $names = $this->parseQueueNames($queue);
        if ($names === []) {
            $names = $this->jobDiscovery->queues();
        }
        if ($names === []) {
            return $this->console->error('No queues discovered.');
        }

        $target = count($names) === 1 ? $names[0] : $names;

        for (; ;) {
            try {
                $envelope = $this->queue->pull($target, $timeout);
                if ($envelope === null) {
                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                    continue;
                }

                $this->queue->consume($envelope);
            } catch (Throwable $e) {
                $this->console->error('Worker error: {message}', ['message' => $e->getMessage()]);
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }
    }

    /** Runs the delayed-message migrator leader loop. */
    public function migrateDueAction(
        string $queue = '',
        int    $sleepMs = 200,
        int    $lockTtl = 15,
        int    $renewEveryMs = 4000,
        int    $limit = 100,
        string $lockKey = 'queue:delay:migrator:leader'
    ): int {
        $names = $this->parseQueueNames($queue);
        if ($names === []) {
            $names = $this->jobDiscovery->queues();
        }
        if ($names === []) {
            return $this->console->error('No queues discovered.');
        }

        if ($lockTtl < 3) {
            return $this->console->error('lockTtl must be >= 3 seconds.');
        }
        if ($renewEveryMs < 200) {
            return $this->console->error('renewEveryMs must be >= 200.');
        }
        if ($limit <= 0) {
            return $this->console->error('limit must be > 0.');
        }

        $token = $this->random->uuid();
        $target = count($names) === 1 ? $names[0] : $names;
        $isLeader = false;
        $lastRenewAtMs = 0;

        for (; ;) {
            try {
                if (!$isLeader) {
                    $isLeader = $this->tryAcquireLeaderLock($lockKey, $token, $lockTtl);
                    if (!$isLeader) {
                        if ($sleepMs > 0) {
                            usleep($sleepMs * 1000);
                        }
                        continue;
                    }

                    $lastRenewAtMs = $this->nowMs();
                }

                $migrated = $this->queue->migrateDue($target, $limit);
                if ($this->nowMs() - $lastRenewAtMs >= $renewEveryMs) {
                    if (!$this->renewLeaderLock($lockKey, $token, $lockTtl)) {
                        $isLeader = false;
                        if ($sleepMs > 0) {
                            usleep($sleepMs * 1000);
                        }
                        continue;
                    }
                    $lastRenewAtMs = $this->nowMs();
                }

                if ($migrated <= 0 && $sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            } catch (Throwable $e) {
                if ($isLeader) {
                    $this->releaseLeaderLock($lockKey, $token);
                    $isLeader = false;
                }

                $this->console->error('Delay worker error: {message}', ['message' => $e->getMessage()]);
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }
    }

    /** @return list<string> */
    protected function parseQueueNames(string $queues): array
    {
        if ($queues === '') {
            return [];
        }

        $parts = array_map(trim(...), explode(',', $queues));

        return array_values(array_unique(array_filter($parts, static fn (string $s): bool => $s !== '')));
    }

    protected function tryAcquireLeaderLock(string $key, string $token, int $ttl): bool
    {
        $result = $this->redisQueue->set($key, $token, ['NX', 'EX' => $ttl]);
        return $result === true || $result === 'OK';
    }

    protected function renewLeaderLock(string $key, string $token, int $ttl): bool
    {
        $owner = $this->redisQueue->get($key);
        if (!is_string($owner) || $owner !== $token) {
            return false;
        }

        $result = $this->redisQueue->expire($key, $ttl);
        return $result === true;
    }

    protected function releaseLeaderLock(string $key, string $token): void
    {
        $owner = $this->redisQueue->get($key);
        if (!is_string($owner) || $owner !== $token) {
            return;
        }

        $this->redisQueue->del($key);
    }

    protected function nowMs(): int
    {
        return (int)(microtime(true) * 1000);
    }
}
