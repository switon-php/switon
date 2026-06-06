<?php

declare(strict_types=1);

namespace Switon\Context\Tests\Unit;

use Switon\Context\Event\ChildContextCreated;
use Switon\Context\Event\ChildContextInherited;
use Switon\Context\Event\ChildContextPropagated;
use Switon\Context\Exception\ContextException;
use Switon\Context\Tests\Fixtures\{TestComponent,
    TestComponentContext,
    TestComponentWithBuiltinReturnType,
    TestComponentWithIsolatedContext,
    TestComponentWithNonLoadableContextReturnType,
    TestComponentWithRequiredConstructorContext,
    TestComponentWithUnionReturnType,
    TestIsolatedContext};
use Switon\Context\Tests\TestCase;
use Switon\Core\Runtime;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use LogicException;

use function extension_loaded;

class ContextManagerTest extends TestCase
{
    public function testFindContextReturnsCorrectClassName(): void
    {
        $component = new TestComponent();

        $contextClassName = $this->contextManager->findContext($component);

        $this->assertSame(TestComponentContext::class, $contextClassName);
    }

    public function testFindContextCachesClassName(): void
    {
        $component1 = new TestComponent();
        $component2 = new TestComponent();

        $contextClassName1 = $this->contextManager->findContext($component1);
        $contextClassName2 = $this->contextManager->findContext($component2);

        $this->assertSame($contextClassName1, $contextClassName2);
        $this->assertSame(TestComponentContext::class, $contextClassName1);
    }

    public function testFindContextThrowsExceptionForBuiltinReturnType(): void
    {
        $component = new TestComponentWithBuiltinReturnType();

        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('builtin type');

        $this->contextManager->findContext($component);
    }

    public function testFindContextThrowsExceptionForUnionReturnType(): void
    {
        $component = new TestComponentWithUnionReturnType();

        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('single context class return type');

        $this->contextManager->findContext($component);
    }

    public function testFindContextThrowsExceptionWhenReturnTypeIsNotLoadable(): void
    {
        $component = new TestComponentWithNonLoadableContextReturnType();

        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('loadable context class');

        $this->contextManager->findContext($component);
    }

    public function testFindContextReturnsInterfaceTypeBeforeInstantiation(): void
    {
        $component = new class () implements \Switon\Core\ContextAware {
            public function getContext(): \Switon\Core\ContextIsolated
            {
                return new TestIsolatedContext();
            }
        };

        $contextClassName = $this->contextManager->findContext($component);

        $this->assertSame(\Switon\Core\ContextIsolated::class, $contextClassName);
    }

    public function testCreateContextThrowsExceptionForInterfaceReturnType(): void
    {
        $component = new class () implements \Switon\Core\ContextAware {
            public function getContext(): \Switon\Core\ContextIsolated
            {
                return new TestIsolatedContext();
            }
        };

        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('instantiable context class');

        $this->contextManager->createContext($component);
    }

    public function testCreateContextThrowsExceptionForAbstractContextClass(): void
    {
        $component = new class () implements \Switon\Core\ContextAware {
            public function getContext(): AbstractContextFixture
            {
                throw new LogicException('Not used in this test');
            }
        };

        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('instantiable context class');

        $this->contextManager->createContext($component);
    }

    public function testCreateContextCreatesNewInstance(): void
    {
        $component = new TestComponent();

        $context1 = $this->contextManager->createContext($component);
        $context2 = $this->contextManager->createContext($component);

        $this->assertInstanceOf(TestComponentContext::class, $context1);
        $this->assertInstanceOf(TestComponentContext::class, $context2);
        $this->assertNotSame($context1, $context2);
    }

    public function testCreateContextThrowsExceptionForRequiredConstructorArguments(): void
    {
        $component = new TestComponentWithRequiredConstructorContext();

        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('without constructor arguments');

        $this->contextManager->createContext($component);
    }

    public function testGetContextReturnsSameInstanceForSameObject(): void
    {
        $component = new TestComponent();

        $context1 = $this->contextManager->getContext($component);
        $context2 = $this->contextManager->getContext($component);

        $this->assertInstanceOf(TestComponentContext::class, $context1);
        $this->assertSame($context1, $context2);
    }

    public function testGetContextReturnsDifferentInstancesForDifferentObjects(): void
    {
        $component1 = new TestComponent();
        $component2 = new TestComponent();

        $context1 = $this->contextManager->getContext($component1);
        $context2 = $this->contextManager->getContext($component2);

        $this->assertInstanceOf(TestComponentContext::class, $context1);
        $this->assertInstanceOf(TestComponentContext::class, $context2);
        $this->assertNotSame($context1, $context2);
    }

    public function testGetContextAllowsIndependentStateModification(): void
    {
        $component1 = new TestComponent();
        $component2 = new TestComponent();

        $context1 = $this->contextManager->getContext($component1);
        $context2 = $this->contextManager->getContext($component2);

        $context1->data['key'] = 'value1';
        $context2->data['key'] = 'value2';

        $this->assertSame('value1', $context1->data['key']);
        $this->assertSame('value2', $context2->data['key']);
    }

    public function testResetContextsClearsAllInstances(): void
    {
        // Ensure this test runs in non-coroutine mode so that ContextManager
        // uses per-object storage ($contexts) instead of coroutine roots.
        Runtime::setCoroutineEnabled(false);

        $component = new TestComponent();
        $originalContext = $this->contextManager->getContext($component);

        $this->contextManager->resetContexts();
        $newContext = $this->contextManager->getContext($component);

        $this->assertNotSame($originalContext, $newContext);
        $this->assertInstanceOf(TestComponentContext::class, $newContext);
    }

    public function testNonCoroutineGetContextDoesNotDispatchChildEvents(): void
    {
        Runtime::setCoroutineEnabled(false);
        $dispatcher = $this->eventDispatcher;
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $dispatcher->clear();

        $component = new TestComponent();
        $this->contextManager->getContext($component);
        $this->contextManager->getContext($component);

        $this->assertFalse($dispatcher->hasDispatched(ChildContextInherited::class));
        $this->assertFalse($dispatcher->hasDispatched(ChildContextCreated::class));
        $this->assertFalse($dispatcher->hasDispatched(ChildContextPropagated::class));
    }

    public function testGetContextWorksWithIsolatedContext(): void
    {
        $component = new TestComponentWithIsolatedContext();

        $context = $this->contextManager->getContext($component);

        $this->assertInstanceOf(TestIsolatedContext::class, $context);
    }

    public function testFindContextWorksWithIsolatedContext(): void
    {
        $component = new TestComponentWithIsolatedContext();

        $contextClassName = $this->contextManager->findContext($component);

        $this->assertSame(TestIsolatedContext::class, $contextClassName);
    }

    public function testGetContextUsesRootStorageWhenCoroutineEnabledWithoutSwooleContext(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        Runtime::setCoroutineEnabled(true);
        try {
            $component = new TestComponent();

            $context1 = $this->contextManager->getContext($component);
            $context1->data['k'] = 'v';
            $context2 = $this->contextManager->getContext($component);

            $this->assertSame($context1, $context2);
            $this->assertSame('v', $context2->data['k']);

            $this->contextManager->resetContexts();
            $context3 = $this->contextManager->getContext($component);
            $this->assertSame($context1, $context3, 'resetContexts() should not clear coroutine root storage');
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testGetContextReturnsCachedInstanceOnRepeatedCalls(): void
    {
        $component = new TestComponent();

        $context1 = $this->contextManager->getContext($component);
        $context2 = $this->contextManager->getContext($component);
        $context3 = $this->contextManager->getContext($component);

        $this->assertSame($context1, $context2);
        $this->assertSame($context2, $context3);
        $this->assertSame($context1, $context3);
    }

    public function testCoroutineContextIsInheritedByDefault(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        Runtime::setCoroutineEnabled(true);

        $component = new TestComponent();
        $manager = $this->contextManager;

        $parentContext = null;
        $childContext = null;

        \Swoole\Coroutine\run(function () use ($manager, $component, &$parentContext, &$childContext): void {
            // Arrange: parent coroutine gets context and writes data
            $parentContext = $manager->getContext($component);
            $parentContext->data['key'] = 'parent';

            // Act: child coroutine accesses same component context
            \Swoole\Coroutine::create(function () use ($manager, $component, &$childContext): void {
                $childContext = $manager->getContext($component);
            });
        });

        // Assert: by default child inherits same context instance
        $this->assertInstanceOf(TestComponentContext::class, $parentContext);
        $this->assertSame($parentContext, $childContext);
        $this->assertSame('parent', $childContext->data['key']);
    }

    public function testCoroutineContextIsIsolatedWhenMarkedContextIsolated(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        Runtime::setCoroutineEnabled(true);

        $component = new TestComponentWithIsolatedContext();
        $manager = $this->contextManager;

        $parentContext = null;
        $childContext = null;

        \Swoole\Coroutine\run(function () use ($manager, $component, &$parentContext, &$childContext): void {
            // Arrange: parent coroutine gets isolated context
            $parentContext = $manager->getContext($component);
            $parentContext->data['counter'] = 1;

            // Act: child coroutine gets its own context instance
            \Swoole\Coroutine::create(function () use ($manager, $component, &$childContext): void {
                $childContext = $manager->getContext($component);
            });
        });

        // Assert: ContextIsolated causes child to receive a different instance
        $this->assertInstanceOf(TestIsolatedContext::class, $parentContext);
        $this->assertInstanceOf(TestIsolatedContext::class, $childContext);
        $this->assertNotSame($parentContext, $childContext);
        $this->assertSame(1, $parentContext->data['counter'] ?? 1);
        $this->assertSame(0, $childContext->counter);
    }

    public function testCoroutineRootContextPersistsAcrossReset(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        Runtime::setCoroutineEnabled(true);

        $component = new TestComponent();

        // Outside of any coroutine: ContextManager should use roots storage
        $first = $this->contextManager->getContext($component);
        $this->contextManager->resetContexts();
        $second = $this->contextManager->getContext($component);

        // roots are not cleared by resetContexts(), so instance must be the same
        $this->assertSame($first, $second);
        $this->assertInstanceOf(TestComponentContext::class, $second);
    }

    public function testCoroutineRootContextsAreIsolatedPerObject(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        Runtime::setCoroutineEnabled(true);

        $component1 = new TestComponent();
        $component2 = new TestComponent();

        // Outside coroutine context: ContextManager uses roots storage.
        $context1 = $this->contextManager->getContext($component1);
        $context2 = $this->contextManager->getContext($component2);

        $this->assertInstanceOf(TestComponentContext::class, $context1);
        $this->assertInstanceOf(TestComponentContext::class, $context2);
        $this->assertNotSame($context1, $context2);
    }

    public function testCoroutineInheritanceDispatchesInheritedEvent(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        Runtime::setCoroutineEnabled(true);

        $component = new TestComponent();
        $manager = $this->contextManager;

        $dispatcher = $this->eventDispatcher;
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $dispatcher->clear();

        \Swoole\Coroutine\run(function () use ($manager, $component): void {
            $manager->getContext($component);

            \Swoole\Coroutine::create(function () use ($manager, $component): void {
                $manager->getContext($component);
            });
        });

        $this->assertTrue($dispatcher->hasDispatched(ChildContextInherited::class));
        $events = $dispatcher->getEventsByClass(ChildContextInherited::class);
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(TestComponent::class, $event->owner);
        $this->assertSame(TestComponentContext::class, $event->context);
    }

    public function testCoroutineIsolationDispatchesCreatedEvent(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        Runtime::setCoroutineEnabled(true);

        $component = new TestComponentWithIsolatedContext();
        $manager = $this->contextManager;

        $dispatcher = $this->eventDispatcher;
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $dispatcher->clear();

        \Swoole\Coroutine\run(function () use ($manager, $component): void {
            $manager->getContext($component);

            \Swoole\Coroutine::create(function () use ($manager, $component): void {
                $manager->getContext($component);
            });
        });

        $this->assertTrue($dispatcher->hasDispatched(ChildContextCreated::class));
        $events = $dispatcher->getEventsByClass(ChildContextCreated::class);
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(TestComponentWithIsolatedContext::class, $event->owner);
        $this->assertSame(TestIsolatedContext::class, $event->context);
    }

    public function testCoroutineContextPropagatesUpSoCousinsShare(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        Runtime::setCoroutineEnabled(true);

        $component = new TestComponent();
        $manager = $this->contextManager;

        $grandchildContext = null;
        $childBContext = null;
        $done = new \Swoole\Coroutine\Channel(1);

        \Swoole\Coroutine\run(function () use ($manager, $component, &$grandchildContext, &$childBContext, $done): void {
            // Parent spawns Child A (which spawns Grandchild)
            \Swoole\Coroutine::create(function () use ($manager, $component, &$grandchildContext, $done): void {
                \Swoole\Coroutine::create(function () use ($manager, $component, &$grandchildContext, $done): void {
                    // Grandchild creates context first (Parent and Child A never touched it)
                    $grandchildContext = $manager->getContext($component);
                    $grandchildContext->data['source'] = 'grandchild';
                    $done->push(true);
                });
            });
            $done->pop(); // Parent waits for Grandchild to complete

            // Parent spawns Child B (sibling of Child A, cousin of Grandchild)
            \Swoole\Coroutine::create(function () use ($manager, $component, &$childBContext): void {
                $childBContext = $manager->getContext($component);
            });
        });

        // Assert: propagation made context available to Parent, so Child B inherited same instance
        $this->assertInstanceOf(TestComponentContext::class, $grandchildContext);
        $this->assertSame($grandchildContext, $childBContext);
        $this->assertSame('grandchild', $childBContext->data['source']);
    }

    public function testCoroutinePropagationDispatchesPropagatedEvent(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        Runtime::setCoroutineEnabled(true);

        $component = new TestComponent();
        $manager = $this->contextManager;

        $dispatcher = $this->eventDispatcher;
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $dispatcher->clear();

        $done = new \Swoole\Coroutine\Channel(1);

        \Swoole\Coroutine\run(function () use ($manager, $component, $done): void {
            \Swoole\Coroutine::create(function () use ($manager, $component, $done): void {
                \Swoole\Coroutine::create(function () use ($manager, $component, $done): void {
                    $manager->getContext($component);
                    $done->push(true);
                });
            });
            $done->pop();
        });

        $this->assertTrue($dispatcher->hasDispatched(ChildContextPropagated::class));
        $events = $dispatcher->getEventsByClass(ChildContextPropagated::class);
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(TestComponent::class, $event->owner);
        $this->assertSame(TestComponentContext::class, $event->context);
    }

    public function testCoroutinePropagationStopsWhenAncestorHasContext(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        Runtime::setCoroutineEnabled(true);

        $component = new TestComponent();
        $manager = $this->contextManager;

        $childBContext = null;
        $grandchildContext = null;
        $done1 = new \Swoole\Coroutine\Channel(1);
        $done2 = new \Swoole\Coroutine\Channel(1);

        \Swoole\Coroutine\run(function () use ($manager, $component, &$childBContext, &$grandchildContext, $done1, $done2): void {
            // Parent spawns Child B first; Child B creates, propagates to Parent
            \Swoole\Coroutine::create(function () use ($manager, $component, &$childBContext, $done1): void {
                $childBContext = $manager->getContext($component);
                $childBContext->data['source'] = 'childB';
                $done1->push(true);
            });
            $done1->pop();

            // Parent spawns Child A, which spawns Grandchild; Grandchild creates (Child A has none)
            // Propagation to Parent stops because Parent already has context from Child B
            \Swoole\Coroutine::create(function () use ($manager, $component, &$grandchildContext, $done2): void {
                \Swoole\Coroutine::create(function () use ($manager, $component, &$grandchildContext, $done2): void {
                    $grandchildContext = $manager->getContext($component);
                    $grandchildContext->data['source'] = 'grandchild';
                    $done2->push(true);
                });
            });
            $done2->pop();
        });

        // Assert: two separate trees; propagation did not overwrite Parent's context
        $this->assertInstanceOf(TestComponentContext::class, $childBContext);
        $this->assertInstanceOf(TestComponentContext::class, $grandchildContext);
        $this->assertNotSame($childBContext, $grandchildContext);
        $this->assertSame('childB', $childBContext->data['source']);
        $this->assertSame('grandchild', $grandchildContext->data['source']);
    }

    public function testResetContextsDoesNotAffectCoroutineScopedContext(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        Runtime::setCoroutineEnabled(true);

        $component = new TestComponent();
        $manager = $this->contextManager;

        $contextBefore = null;
        $contextAfter = null;

        \Swoole\Coroutine\run(function () use ($manager, $component, &$contextBefore, &$contextAfter): void {
            $contextBefore = $manager->getContext($component);
            $contextBefore->data['key'] = 'value';
            $manager->resetContexts();
            $contextAfter = $manager->getContext($component);
        });

        // Assert: resetContexts clears only non-coroutine storage; coroutine context unchanged
        $this->assertSame($contextBefore, $contextAfter);
        $this->assertSame('value', $contextAfter->data['key']);
    }

    public function testDisableCoroutineAfterRootContextUsesIndependentNonCoroutineStorage(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        $component = new TestComponent();

        Runtime::setCoroutineEnabled(true);
        $rootContext = $this->contextManager->getContext($component);
        $rootContext->data['mode'] = 'coroutine-root';

        Runtime::setCoroutineEnabled(false);
        try {
            $normalContext = $this->contextManager->getContext($component);
            $this->assertNotSame($rootContext, $normalContext);
            $this->assertArrayNotHasKey('mode', $normalContext->data);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testEnableCoroutineAfterNonCoroutineContextUsesIndependentRootStorage(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        $component = new TestComponent();

        Runtime::setCoroutineEnabled(false);
        $normalContext = $this->contextManager->getContext($component);
        $normalContext->data['mode'] = 'normal';

        Runtime::setCoroutineEnabled(true);
        try {
            $rootContext = $this->contextManager->getContext($component);
            $this->assertNotSame($normalContext, $rootContext);
            $this->assertArrayNotHasKey('mode', $rootContext->data);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testCoroutineRootStorageIsIsolatedPerObjectWithoutSwooleContext(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded; coroutine tests skipped.');
        }

        Runtime::setCoroutineEnabled(true);
        try {
            $component1 = new TestComponent();
            $component2 = new TestComponent();

            $context1 = $this->contextManager->getContext($component1);
            $context2 = $this->contextManager->getContext($component2);

            $this->assertInstanceOf(TestComponentContext::class, $context1);
            $this->assertInstanceOf(TestComponentContext::class, $context2);
            $this->assertNotSame($context1, $context2);
        } finally {
            Runtime::setCoroutineEnabled(false);
        }
    }

    public function testResetContextsDoesNotAffectFindContextClassCache(): void
    {
        $component = new TestComponent();
        $classBefore = $this->contextManager->findContext($component);

        $this->contextManager->resetContexts();

        $classAfter = $this->contextManager->findContext($component);
        $this->assertSame($classBefore, $classAfter);
        $this->assertSame(TestComponentContext::class, $classAfter);
    }
}

abstract class AbstractContextFixture
{
}
