<?php

declare(strict_types=1);

namespace Switon\Queue\Attribute;

use Attribute;
use Switon\Core\RunnerInterface;

/**
 * Binds a {@see RunnerInterface} implementation to a logical queue definition for {@see \Switon\Queue\JobDiscovery}.
 *
 * Guidance: one logical queue name per class; duplicates across classes are rejected during discovery scan.
 *
 * Road-signs:
 * - queue for the logical queue name
 * - concurrency for executor submit threshold
 *
 * @see \Switon\Queue\JobDiscovery
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Queued
{
    /**
     * @param string $queue Logical queue name discovered by {@see \Switon\Queue\JobDiscovery}
     * @param int $concurrency Values >= 1; values greater than 1 dispatch through executor submit()
     */
    public function __construct(
        public string $queue,
        public int    $concurrency = 1,
    ) {
    }
}
