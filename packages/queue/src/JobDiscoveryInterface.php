<?php

declare(strict_types=1);

namespace Switon\Queue;

use Switon\Core\RunnerInterface;

/**
 * Scans queue jobs and exposes resolved queue definitions.
 *
 * Use when queue workers or tooling need the stable map of logical queue name
 * to runner class and concurrency settings.
 *
 * Road-signs:
 * - all() for full queue => job definition map
 * - queues() for queue-name list only
 *
 * @see \Switon\Queue\JobDiscovery
 */
interface JobDiscoveryInterface
{
    /**
     * Returns all discovered queue definitions.
     *
     * @return array<string, array{class: class-string<RunnerInterface>, concurrency: int}> queue => definition
     */
    public function all(): array;

    /**
     * Returns discovered logical queue names.
     *
     * @return list<string>
     */
    public function queues(): array;
}
