<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Switon\Core\ContextManagerInterface;
use Switon\Core\InjectorInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Core\ResourceAliasRegistrar;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Inspector\ServiceProvider as InspectorServiceProvider;
use Switon\Testing\InMemoryContextManager;

/**
 * Base test case for Inspector package tests.
 *
 * Builds a minimal DI container with path aliases and in-memory context storage.
 */
abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected ContextManagerInterface $contextManager;
    protected InjectorInterface $injector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        $pathAlias = new PathAlias();
        $pathAlias->set('@root', '/test/root');
        $pathAlias->set('@runtime', sys_get_temp_dir() . '/switon_test_runtime_' . uniqid('', true));
        $this->container->set(PathAliasInterface::class, $pathAlias);
        $this->container->set(PathAlias::class, $pathAlias);
        $this->container->make(ResourceAliasRegistrar::class)
            ->register($pathAlias, InspectorServiceProvider::class);
        $this->container->set(ContextManagerInterface::class, InMemoryContextManager::class);

        $this->injector = $this->container->get(InjectorInterface::class);
        $this->contextManager = $this->container->get(ContextManagerInterface::class);
    }
}
