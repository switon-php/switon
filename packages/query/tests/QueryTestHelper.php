<?php

declare(strict_types=1);

namespace Switon\Query\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\ContainerInterface;
use Switon\Di\NamedLookupInterface;
use Switon\Query\Query;
use Switon\Sharding\ShardingManagerInterface;

/**
 * Helper trait for Query tests.
 *
 * Provides common setup methods to reduce duplication.
 */
trait QueryTestHelper
{
    /**
     * Creates a Query instance with mocked dependencies using container injection.
     *
     * Uses container dependency injection instead of reflection to set up Query
     * with mocked dependencies. This ensures tests use the same dependency injection
     * mechanism as production code.
     *
     * @param \Switon\Di\Container $container Container instance (must be provided by test)
     * @param MockObject|EventDispatcherInterface $eventDispatcher Mock event dispatcher
     * @param MockObject|NamedLookupInterface $namedLookup Mock named lookup
     * @param MockObject|ContainerInterface $containerInterface Mock container interface
     * @param MockObject|ShardingManagerInterface $shardingManager Mock sharding manager
     *
     * @return Query Query instance with dependencies injected via container
     */
    protected function createQueryWithMocks(
        \Switon\Di\Container                $container,
        MockObject|EventDispatcherInterface $eventDispatcher,
        MockObject|NamedLookupInterface     $namedLookup,
        MockObject|ContainerInterface       $containerInterface,
        MockObject|ShardingManagerInterface $shardingManager
    ): Query {
        // Register all dependencies in container
        $container->set(EventDispatcherInterface::class, $eventDispatcher);
        $container->set(NamedLookupInterface::class, $namedLookup);
        $container->set(ContainerInterface::class, $containerInterface);
        $container->set(\Psr\Container\ContainerInterface::class, $containerInterface);
        $container->set(ShardingManagerInterface::class, $shardingManager);

        // Register Query class in container
        $container->set(Query::class, Query::class);

        // Get Query instance from container (will auto-inject dependencies)
        return $container->get(Query::class);
    }
}
