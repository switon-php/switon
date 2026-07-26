<?php

declare(strict_types=1);

namespace Switon\Routing\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Switon\Core\App;
use Switon\Core\ContextManagerInterface;
use Switon\Core\InjectorInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Routing\PatternCompilerInterface;
use Switon\Routing\Router;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;

abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected InjectorInterface $injector;

    protected Router $router;
    protected Fixtures\MockRequest $request;
    protected Fixtures\MockPatternCompiler $patternCompiler;
    protected Fixtures\MockEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        App::setContainer($this->container);
        $this->container->set(ContextManagerInterface::class, InMemoryContextManager::class);

        $realDispatcher = $this->container->make(RealEventDispatcher::class);
        $defaultDispatcher = new TestEventDispatcher($realDispatcher);
        $defaultDispatcher->setDispatchEvents(false);
        $this->container->set(EventDispatcherInterface::class, $defaultDispatcher);
        $this->container->set(PsrEventDispatcherInterface::class, $defaultDispatcher);

        $this->injector = $this->container->get(InjectorInterface::class);
        $this->setUpContainer();
        $this->injector->inject($this);

        $this->request = new Fixtures\MockRequest();
        $this->patternCompiler = new Fixtures\MockPatternCompiler();
        $this->eventDispatcher = new Fixtures\MockEventDispatcher();

        $this->container->replace(PatternCompilerInterface::class, $this->patternCompiler);
        $this->container->replace(PsrEventDispatcherInterface::class, $this->eventDispatcher);

        $this->router = $this->make(Router::class);
    }

    protected function setUpContainer(): void
    {
    }

    protected function make(string $name, array $parameters = []): mixed
    {
        return $this->container->make($name, $parameters);
    }
}
