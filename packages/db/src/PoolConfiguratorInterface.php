<?php

declare(strict_types=1);

namespace Switon\Db;

/**
 * Builds connection pool configuration from DB URI input.
 *
 * @see \Switon\Db\PoolConfigurator
 * @see \Switon\Db\Client
 */
interface PoolConfiguratorInterface
{
    /** Parses URI and configures pool manager entries for the given client owner. */
    public function configure(object $owner, string $uri): PoolConfiguration;
}
