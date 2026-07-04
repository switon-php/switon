<?php

declare(strict_types=1);

namespace Switon\Db\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use Psr\Log\LoggerInterface;
use Switon\Core\App;
use Switon\Core\ContextManagerInterface;
use Switon\Core\InjectorInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Db\ClientInterface;
use Switon\Db\Tests\Fixtures\StubDbClient;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;
use Switon\Testing\MockLogger;
use Throwable;

use function sys_get_temp_dir;

/**
 * Base test case for DB tests.
 */
abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected InjectorInterface $injector;
    protected ClientInterface $poolOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        App::setContainer($this->container);

        $pathAlias = new PathAlias();
        $pathAlias->set('@runtime', sys_get_temp_dir());
        $this->container->set(PathAliasInterface::class, $pathAlias);
        $this->container->set(PathAlias::class, $pathAlias);
        $this->container->set(ContextManagerInterface::class, InMemoryContextManager::class);
        $this->container->set(LoggerInterface::class, new MockLogger());

        $this->container->set(RealEventDispatcher::class, RealEventDispatcher::class);
        $this->container->set(EventDispatcherInterface::class, function (): TestEventDispatcher {
            $dispatcher = new TestEventDispatcher($this->container->get(RealEventDispatcher::class));
            $dispatcher->setDispatchEvents(false);

            return $dispatcher;
        });
        $this->container->set(PsrEventDispatcherInterface::class, EventDispatcherInterface::class);
        $this->container->set(PsrListenerProviderInterface::class, ListenerProviderInterface::class);

        $this->injector = $this->container->get(InjectorInterface::class);
        $this->setUpContainer();
        $this->injector->inject($this);

        $this->poolOwner = new StubDbClient();
        App::setContainer($this->container);
    }

    protected function setUpContainer(): void
    {
    }

    protected function make(string $name, array $parameters = []): mixed
    {
        return $this->container->make($name, $parameters);
    }

    protected function tearDown(): void
    {
        if (isset($this->client) && $this->client instanceof \Switon\Db\Client) {
            try {
                $this->client->close();
            } catch (Throwable) {
            }
        }

        App::setContainer(new DiContainer());
        parent::tearDown();
    }
}
