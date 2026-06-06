<?php

declare(strict_types=1);

namespace Switon\Db;

/**
 * Connection pool configuration value object.
 *
 * Immutable data structure containing parsed connection pool settings.
 *
 * @see \Switon\Db\PoolConfigurator
 * @see \Switon\Db\Client
 */
readonly class PoolConfiguration
{
    public function __construct(
        public float  $timeout,
        public string $prefix,
        public bool   $hasSlave,
    ) {
    }
}
