<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Switon\Core\App;
use Switon\Core\ContextManagerInterface;
use Switon\Core\InjectorInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface;
use ReflectionMethod;
use Switon\OpenApi\PhpReturnSchema;
use Switon\OpenApi\ResponseSchemaGuesser;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;

abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected InjectorInterface $injector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        App::setContainer($this->container);

        $pathAlias = new PathAlias();
        $this->container->set(PathAliasInterface::class, $pathAlias);
        $this->container->set(PathAlias::class, $pathAlias);
        $this->container->set(ContextManagerInterface::class, InMemoryContextManager::class);

        $realDispatcher = $this->container->make(RealEventDispatcher::class);
        $eventDispatcher = new TestEventDispatcher($realDispatcher);
        $eventDispatcher->setDispatchEvents(false);
        $this->container->set(EventDispatcherInterface::class, $eventDispatcher);
        $this->container->set(PsrEventDispatcherInterface::class, $eventDispatcher);

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

    /**
     * @param class-string $class
     *
     * @return array<string, mixed>|null
     */
    protected function inferReturnSchema(string $class, string $action, ?PhpReturnSchema $infer = null): ?array
    {
        $infer ??= new PhpReturnSchema();

        return $infer->inferSchemaFromReturnType(new ReflectionMethod($class, $action));
    }

    /**
     * @param class-string $class
     *
     * @return array<string, mixed>|null
     */
    protected function guessResponseSchema(string $class, string $action, ?ResponseSchemaGuesser $guesser = null): ?array
    {
        $guesser ??= $this->make(ResponseSchemaGuesser::class, [
            'phpReturnSchema' => new PhpReturnSchema(),
        ]);

        return $guesser->guess(new ReflectionMethod($class, $action));
    }
}
