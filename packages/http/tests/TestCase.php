<?php

declare(strict_types=1);

namespace Switon\Http\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use Psr\Log\LoggerInterface;
use Switon\Core\App;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Clock;
use Switon\Core\ContextManagerInterface;
use Switon\Core\InjectorInterface;
use Switon\Core\InputInterface;
use Switon\Core\LocaleInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Core\ResourceAliasRegistrar;
use Switon\Core\TranslatorInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Http\RequestInterface;
use Switon\Http\ServiceProvider as HttpServiceProvider;
use Switon\Invoking\InvokerInterface;
use Switon\Id\ServiceProvider as IdServiceProvider;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;
use Switon\Testing\Mock\StubTranslator;
use Switon\Testing\MockLogger;
use Switon\Validating\Validator;
use Switon\Validating\ValidatorInterface;
use ReflectionMethod;
use ReflectionParameter;

use function sys_get_temp_dir;

/**
 * Base test case for HTTP tests.
 */
abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected InjectorInterface $injector;

    #[Autowired] protected ContextManagerInterface $contextManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new DiContainer();
        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        App::setContainer($this->container);

        $pathAlias = new PathAlias();
        $pathAlias->set('@view', sys_get_temp_dir() . '/switon_test_view_' . uniqid('', true));
        $pathAlias->set('@public', sys_get_temp_dir() . '/switon_test_public_' . uniqid('', true));
        $pathAlias->set('@runtime', sys_get_temp_dir() . '/switon_test_runtime_' . uniqid('', true));
        $validatorTemplateDir = dirname(__DIR__, 2) . '/validation/src/Templates';
        $pathAlias->set('@switon.validator.resources', $validatorTemplateDir);
        $this->container->set(PathAliasInterface::class, $pathAlias);
        $this->container->set(PathAlias::class, $pathAlias);
        $this->container->set(ContextManagerInterface::class, InMemoryContextManager::class);
        $this->container->set(LoggerInterface::class, new MockLogger());
        $this->container->set(TranslatorInterface::class, new StubTranslator());
        $this->container->set(Clock::class, Clock::class);

        $locale = $this->createStub(LocaleInterface::class);
        $locale->method('get')->willReturn('en');
        $locale->method('set')->willReturnSelf();
        $this->container->set(LocaleInterface::class, $locale);

        $validator = $this->container->make(Validator::class, ['dirs' => [$validatorTemplateDir]]);
        $this->container->set(ValidatorInterface::class, $validator);

        $this->container->set(App::class, [
            'id' => 'test-app',
            'env' => 'test',
            'debug' => true,
        ]);

        $this->container->make(ResourceAliasRegistrar::class)
            ->register($pathAlias, HttpServiceProvider::class);

        (new IdServiceProvider())->register($this->container);

        $this->container->set(RealEventDispatcher::class, RealEventDispatcher::class);
        $this->container->set(EventDispatcherInterface::class, function (): TestEventDispatcher {
            return new TestEventDispatcher($this->container->get(RealEventDispatcher::class));
        });
        $this->container->set(PsrEventDispatcherInterface::class, EventDispatcherInterface::class);
        $this->container->set(PsrListenerProviderInterface::class, ListenerProviderInterface::class);

        $this->injector = $this->container->get(InjectorInterface::class);
        $this->setUpContainer();
        $this->injector->inject($this);
    }

    protected function setUpContainer(): void
    {
        $this->beforeSetUpHttpContainer();
        $this->setUpHttpContainer();
    }

    protected function beforeSetUpHttpContainer(): void
    {
    }

    protected function setUpHttpContainer(): void
    {
        $this->container->set(InputInterface::class, RequestInterface::class);
    }

    protected function make(string $name, array $parameters = []): mixed
    {
        return $this->container->make($name, $parameters);
    }

    /**
     * Real parameter for value-resolver tests.
     *
     * Resolvers accept a <code>ReflectionParameter</code> they do not read, so any declared
     * parameter will do; using a real one keeps the type honest instead of fabricating it.
     */
    protected function resolverParameter(): ReflectionParameter
    {
        return (new ReflectionMethod($this, 'resolverTarget'))->getParameters()[0];
    }

    protected function resolverTarget(object $input): void
    {
    }

    /**
     * Invokes an action through the guard/interceptor-aware invoker (no ReflectionMethod in tests).
     *
     * @param array<int|string, mixed> $parameters
     */
    protected function invokeAction(object $target, string $method, array $parameters = []): mixed
    {
        return $this->container->get(InvokerInterface::class)->invoke([$target, $method], $parameters);
    }
}
