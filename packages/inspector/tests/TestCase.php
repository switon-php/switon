<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Switon\Core\ContextManagerInterface;
use Switon\Core\InjectorInterface;
use Switon\Core\PathAliasInterface;
use Switon\Testing\Container;

/**
 * Base test case for Inspector package tests.
 */
abstract class TestCase extends BaseTestCase
{
    protected Container $container;
    protected ContextManagerInterface $contextManager;
    protected InjectorInterface $injector;

    protected function setUp(): void
    {
        parent::setUp();

        // Use pre-configured test container (ContextManagerInterface and PathAliasInterface are already registered)
        $this->container = new Container();
        $this->injector = $this->container->get(InjectorInterface::class);

        // Get ContextManager from container
        $this->contextManager = $this->container->get(ContextManagerInterface::class);

        // Set additional PathAlias aliases for Inspector tests
        $pathAlias = $this->container->get(PathAliasInterface::class);
        $pathAlias->set('@root', '/test/root');
        $pathAlias->set('@runtime', sys_get_temp_dir() . '/switon_test_runtime_' . uniqid('', true));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
