<?php

declare(strict_types=1);

namespace Switon\Langfuse\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use Psr\Log\LoggerInterface;
use Switon\Core\App;
use Switon\Core\ContextManagerInterface;
use Switon\Core\InjectorInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\HttpClient\HttpClientInterface;
use Switon\Langfuse\Tests\Fixtures\CapturingHttpClient;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;
use Switon\Testing\MockLogger;

abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected InjectorInterface $injector;
    protected CapturingHttpClient $httpClient;
    protected MockLogger $logger;

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
        $eventDispatcher = new TestEventDispatcher($realDispatcher);
        $this->container->set(EventDispatcherInterface::class, $eventDispatcher);
        $this->container->set(PsrEventDispatcherInterface::class, $eventDispatcher);
        $this->container->set(PsrListenerProviderInterface::class, ListenerProviderInterface::class);

        $this->httpClient = new CapturingHttpClient();
        $this->logger = new MockLogger();
        $this->container->set(HttpClientInterface::class, $this->httpClient);
        $this->container->set(LoggerInterface::class, $this->logger);

        $this->injector = $this->container->get(InjectorInterface::class);
        $this->setUpContainer();
        $this->injector->inject($this);
    }

    protected function setUpContainer(): void
    {
    }

    protected function make(string $name, array $parameters = []): mixed
    {
        return $this->container->make($name, $parameters);
    }
}
