<?php

declare(strict_types=1);

namespace Switon\Core;

use function microtime;
use function round;
use function time;

/**
 * System-time implementation of `ClockInterface`.
 *
 * @see \Switon\Core\ClockInterface
 * @see \Switon\Testing\MockClock
 */
class Clock implements ClockInterface
{
    /**
     * {@inheritDoc}
     */
    public function time(): int
    {
        return time();
    }

    /**
     * {@inheritDoc}
     */
    public function microtime(): float
    {
        return microtime(true);
    }

    /**
     * {@inheritDoc}
     */
    public function now(int $precision = 3): float
    {
        return round($this->microtime(), $precision);
    }

    /**
     * {@inheritDoc}
     */
    public function elapsed(float $startedAt, int $precision = 3): float
    {
        return round($this->microtime() - $startedAt, $precision);
    }
}
