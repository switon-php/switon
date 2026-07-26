<?php

declare(strict_types=1);

namespace Switon\Schedule\Attribute;

use Attribute;

/**
 * Binds schedule metadata on a runner class executed by {@see \Switon\Schedule\TaskRunner}.
 *
 * Guidance: Scheduled classes should implement {@see \Switon\Core\RunnerInterface}; <code>$expression</code> is four fields (second minute hour day-of-month) in {@see \Switon\Schedule\Expression} grammar, interpreted in {@see \Switon\Core\AppInterface::timezone()} (not full crontab); task id is derived at {@see \Switon\Schedule\Schedule::register()} via {@see \Switon\Schedule\Schedule::taskClassToId()}, not {@see \Switon\Routing\HandlerId}.
 *
 * Road-signs:
 * - Schedule register
 * - Expression
 * - lockTtl 0 vs Redis EX
 *
 * @see \Switon\Schedule\Schedule::register()
 * @see \Switon\Schedule\Expression
 * @see \Switon\Schedule\TaskRunner
 * @see \Switon\Schedule\Schedule::taskClassToId()
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Scheduled
{
    /**
     * @param string $expression Four fields, see {@see \Switon\Schedule\Expression}.
     * @param int $lockTtl <code>0</code> — no distributed lock (each PHP process may run the task). <code>1…86400</code> — Redis NX+EX for cross-replica exclusivity (seconds).
     */
    public function __construct(
        public string $expression,
        public int    $lockTtl = 0,
    ) {
    }
}
