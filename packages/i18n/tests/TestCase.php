<?php

declare(strict_types=1);

namespace Switon\I18n\Tests;

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

use function sys_get_temp_dir;

/**
 * Base test case for I18n tests.
 *
 * Builds a minimal DI container with path aliases and in-memory context storage.
 */
abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected ContextManagerInterface $contextManager;
    protected PathAlias $pathAlias;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        $this->pathAlias = new PathAlias();
        $this->pathAlias->set('@i18n', sys_get_temp_dir() . '/switon_i18n_test_' . uniqid('', true));
        $this->container->set(PathAliasInterface::class, $this->pathAlias);
        $this->container->set(PathAlias::class, $this->pathAlias);
        $this->container->set(ContextManagerInterface::class, InMemoryContextManager::class);

        $realDispatcher = $this->container->make(RealEventDispatcher::class);
        $eventDispatcher = new TestEventDispatcher($realDispatcher);
        $eventDispatcher->setDispatchEvents(false);
        $this->container->set(EventDispatcherInterface::class, $eventDispatcher);
        $this->container->set(PsrEventDispatcherInterface::class, $eventDispatcher);

        $this->contextManager = $this->container->get(ContextManagerInterface::class);
    }
}
