<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Switon\Captcha\CaptchaInterface;
use Switon\Captcha\StoreInterface;
use Switon\Captcha\Tests\Fixtures\MockStore;
use Switon\Core\ClockInterface;
use Switon\Core\ContextManagerInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Core\RandomInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;
use Switon\Testing\MockClock;
use Switon\Testing\MockRandom;

/**
 * Base test case for Captcha tests.
 */
abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected MockStore $mockStore;
    protected MockRandom $mockRandom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        $pathAlias = new PathAlias();
        $pathAlias->set('@switon.captcha.resources', dirname(__DIR__) . '/resources');
        $this->container->set(PathAliasInterface::class, $pathAlias);
        $this->container->set(PathAlias::class, $pathAlias);
        $this->container->set(ContextManagerInterface::class, InMemoryContextManager::class);
        $this->container->set(ClockInterface::class, new MockClock(time()));

        $realDispatcher = $this->container->make(RealEventDispatcher::class);
        $eventDispatcher = new TestEventDispatcher($realDispatcher);
        $eventDispatcher->setDispatchEvents(false);
        $this->container->set(EventDispatcherInterface::class, $eventDispatcher);
        $this->container->set(PsrEventDispatcherInterface::class, $eventDispatcher);

        $this->setUpContainer();
    }

    protected function setUpContainer(): void
    {
        $this->mockStore = new MockStore();
        $this->mockRandom = new MockRandom(null, 12345);

        $this->container->set(StoreInterface::class, $this->mockStore);
        $this->container->set(RandomInterface::class, $this->mockRandom);
    }

    /**
     * Creates a CAPTCHA instance for testing.
     *
     * @param array<string, mixed> $parameters Optional constructor parameters
     */
    protected function createCaptcha(array $parameters = []): CaptchaInterface
    {
        return $this->make(CaptchaInterface::class, $parameters);
    }

    protected function getFontPath(string $font = 'Vera.ttf'): string
    {
        /** @var PathAliasInterface $pathAlias */
        $pathAlias = $this->container->get(PathAliasInterface::class);

        return $pathAlias->resolve('@switon.captcha.resources/Fonts/' . $font);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $name
     *
     * @phpstan-return T
     */
    protected function make(string $name, array $parameters = []): mixed
    {
        return $this->container->make($name, $parameters);
    }
}
