<?php

declare(strict_types=1);

namespace Switon\Schedule;

/**
 * Distributed exclusivity for cluster-scoped scheduled tasks.
 *
 * Use when positive `lockTtl` values should suppress duplicate task runs across replicas.
 *
 * @see \Switon\Schedule\Lock
 * @see \Switon\Schedule\TaskRunner
 */
interface LockInterface
{
    /** Tries to acquire a lock with TTL seconds; returns whether this runner owns it. */
    public function tryAcquire(string $key, int $ttlSeconds): bool;

    /** Best-effort release when the lock is no longer needed. */
    public function release(string $key): void;
}
