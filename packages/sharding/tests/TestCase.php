<?php

declare(strict_types=1);

namespace Switon\Sharding\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Sharding\ShardingManager;
use Switon\Sharding\ShardingManagerInterface;

/**
 * Base test case for Sharding tests.
 *
 * Builds a minimal DI container for {@see ShardingManager} only — no full app provider scan.
 */
abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected ShardingManagerInterface $shardingManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        $this->shardingManager = $this->container->make(ShardingManager::class);
    }
}
