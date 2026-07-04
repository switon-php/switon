<?php

declare(strict_types=1);

namespace Switon\Context\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Switon\Context\ContextManager;
use Switon\Context\ContextManagerInterface;
use Switon\Context\ServiceProvider as ContextServiceProvider;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;

/**
 * Base test case for Context tests.
 *
 * Builds a minimal DI container for {@see ContextManager} with a recording event dispatcher.
 */
abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;

    protected ContextManager $contextManager;
    protected EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        (new ContextServiceProvider())->register($this->container);

        $realDispatcher = $this->container->make(RealEventDispatcher::class);
        $this->eventDispatcher = new TestEventDispatcher($realDispatcher);
        $this->eventDispatcher->setDispatchEvents(false);

        $this->container->set(EventDispatcherInterface::class, $this->eventDispatcher);
        $this->container->set(PsrEventDispatcherInterface::class, $this->eventDispatcher);

        $this->contextManager = $this->container->make(ContextManager::class);
    }
}
