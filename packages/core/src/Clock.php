<?php

declare(strict_types=1);

namespace Switon\Core;

use function microtime;
use function round;
use function time;
use function usleep;

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

    /**
     * {@inheritDoc}
     */
    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
