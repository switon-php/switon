<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Switon\Core\ContextManagerInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;

/**
 * Base test case for Renderer tests.
 *
 * Builds a minimal DI container with path aliases and a recording event dispatcher.
 */
abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected EventDispatcherInterface $eventDispatcher;
    protected PathAlias $pathAlias;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        $this->pathAlias = new PathAlias();
        $this->pathAlias->set('@root', $this->getTestTempDir());
        $this->container->set(PathAliasInterface::class, $this->pathAlias);
        $this->container->set(PathAlias::class, $this->pathAlias);
        $this->container->set(ContextManagerInterface::class, InMemoryContextManager::class);

        $realDispatcher = $this->container->make(RealEventDispatcher::class);
        $this->eventDispatcher = new TestEventDispatcher($realDispatcher);
        $this->eventDispatcher->setDispatchEvents(false);

        $this->container->set(EventDispatcherInterface::class, $this->eventDispatcher);
        $this->container->set(PsrEventDispatcherInterface::class, $this->eventDispatcher);
    }

    protected function getTestTempDir(): string
    {
        return sys_get_temp_dir() . '/switon-renderer-tests';
    }
}
