<?php

declare(strict_types=1);

namespace Switon\Llm\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Model;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Core\App;
use Switon\Core\ContextManagerInterface;
use Switon\Core\InjectorInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Llm\LlmDiscovery;
use Switon\Llm\LlmDiscoveryInterface;
use Switon\Llm\ServiceProvider as LlmServiceProvider;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;

/**
 * Base test case for LLM tests.
 */
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
        $this->container->set(ContextManagerInterface::class, InMemoryContextManager::class);

        $realDispatcher = $this->container->make(RealEventDispatcher::class);
        $eventDispatcher = new TestEventDispatcher($realDispatcher);
        $this->container->set(EventDispatcherInterface::class, $eventDispatcher);
        $this->container->set(PsrEventDispatcherInterface::class, $eventDispatcher);
        $this->container->set(PsrListenerProviderInterface::class, ListenerProviderInterface::class);

        $this->injector = $this->container->get(InjectorInterface::class);
        $this->setUpContainer();
        $this->injector->inject($this);
    }

    protected function setUpContainer(): void
    {
        if (!$this->container->has(PathAliasInterface::class)) {
            $pathAlias = new PathAlias();
            $this->container->set(PathAlias::class, $pathAlias);
            $this->container->set(PathAliasInterface::class, $pathAlias);
        }

        // Unit tests must not read the developer home llm.yml.
        $this->container->set(LlmDiscoveryInterface::class, [
            'class' => LlmDiscovery::class,
            'file' => null,
        ]);

        $llmProvider = new LlmServiceProvider();
        $this->injector->inject($llmProvider);
        $llmProvider->register($this->container);
        $llmProvider->boot();
    }

    protected function make(string $name, array $parameters = []): mixed
    {
        if (isset($parameters['model']) && is_string($parameters['model'])) {
            $window = $parameters['window'] ?? 0;
            $extra = $parameters['extra'] ?? [];
            unset($parameters['window'], $parameters['extra']);
            $parameters['model'] = Model::of(
                model: $parameters['model'],
                window: is_int($window) ? $window : 0,
                extra: is_array($extra) ? $extra : [],
            );
        }

        return $this->container->make($name, $parameters);
    }


    /**
     * @param list<Completion> $completions
     *
     * @return list<Message>
     */
    protected function flattenWireMessages(array $completions, ?Completion $omit = null): array
    {
        $messages = [];
        foreach ($completions as $completion) {
            if ($omit !== null && $completion === $omit) {
                continue;
            }
            $messages[] = $completion->input;
            if ($completion->output !== null) {
                $messages[] = $completion->output;
            }
        }

        return $messages;
    }

    /** Hang working slot on conversation for {@see LlmInterface} tests. */
    protected function hang(Thread $thread, Completion $completion, string $model = 'main'): Thread
    {
        if ($thread->turns === [] || $thread->lastTurn()->isSettled()) {
            $thread->turns[] = Turn::of($completion->completeId, [$completion], TurnStatus::Running, $model);

            return $thread;
        }
        $turn = $thread->lastTurn();
        if ($turn->completions === [] || $turn->lastCompletion() !== $completion) {
            $turn->completions[] = $completion;
        }

        return $thread;
    }
}
