<?php

declare(strict_types=1);

namespace Switon\Context\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Context\ContextManager;
use Switon\Context\ContextManagerInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Testing\Container;

abstract class TestCase extends BaseTestCase
{
    protected Container $container;

    #[Autowired] protected ContextManager $contextManager;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        // Use pre-configured test container (ContextManager and EventDispatcher are already registered)
        $this->container = new Container();

        // Disable event dispatching (Context tests don't need real event handling)
        // This is equivalent to NoOp behavior - events are collected but not dispatched
        $this->container->disableEventDispatching();

        // Get EventDispatcher from container (TestEventDispatcher wrapper with dispatching disabled)
        $this->eventDispatcher = $this->container->get(EventDispatcherInterface::class);

        // Get ContextManager from container (already registered)
        $this->contextManager = $this->container->get(ContextManagerInterface::class);
    }
}
