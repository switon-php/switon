<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit\Collectors;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Log\LoggerInterface;
use Switon\Core\ContextManagerInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Eventing\ObservabilityProbe;
use Switon\Eventing\PrioritizedListeners;
use Switon\Inspector\Collector\EventCollector;
use Switon\Inspector\Collector\EventCollectorContext;
use Switon\Logging\Event\LoggerLogged;
use Switon\Logging\LogEntry;

#[AllowMockObjectsWithoutExpectations]
class EventCollectorTest extends BaseTestCase
{
    public function testBootRegistersCollectorAsListener(): void
    {
        $collector = new EventCollectorTestable();
        $listenerProvider = $this->createMock(ListenerProviderInterface::class);
        $listenerProvider->expects($this->once())
            ->method('register')
            ->with($collector);

        $collector->setListenerProvider($listenerProvider);

        $collector->boot();
    }

    public function testOnEventSkipsLoggerLoggedEvent(): void
    {
        $collector = new EventCollectorTestable();
        $context = new EventCollectorContext();
        $collector->setContextManager($this->createContextManager($collector, $context));

        $logger = $this->createStub(LoggerInterface::class);
        $collector->onEvent(new LoggerLogged(
            $logger,
            'info',
            'ignored',
            [],
            new LogEntry('info', 'host', 'H:i:s.v', 1700000000.123)
        ));

        $this->assertSame([], $collector->collect()['list']);
    }

    public function testOnEventAppendsEventMetadataAndClassifiesListeners(): void
    {
        $collector = new EventCollectorTestable();
        $context = new EventCollectorContext();
        $collector->setContextManager($this->createContextManager($collector, $context));

        $listenerProvider = $this->createMock(ListenerProviderInterface::class);
        $eventListeners = new PrioritizedListeners();
        $eventListeners->add([$collector, 'onEvent']);
        $eventListeners->add([new EventCollectorObservabilityListener(), 'onEvent']);

        $wildcardListeners = new PrioritizedListeners();
        $wildcardListeners->add([new EventCollectorBusinessListener(), 'onEvent']);
        $wildcardListeners->add(static function (object $event): void {
        });

        $listenerProvider->method('getListenersForEvent')->willReturn($eventListeners);
        $listenerProvider->method('getListenersForWildcard')->willReturnCallback(static fn () => $wildcardListeners);
        $collector->setListenerProvider($listenerProvider);

        $event = new EventCollectorTestEvent();
        $collector->onEvent($event);

        $result = $collector->collect();

        $this->assertCount(1, $result['list']);
        $this->assertSame(EventCollectorTestEvent::class, $result['list'][0]['event']);
        $this->assertSame(['foo', 'bar'], $result['list'][0]['source']);
        $this->assertSame(
            [
                [
                    'name' => EventCollectorObservabilityListener::class . '::onEvent',
                    'type' => 'observability',
                ],
                [
                    'name' => EventCollectorBusinessListener::class . '::onEvent',
                    'type' => 'business',
                ],
                [
                    'name' => 'Closure',
                    'type' => 'business',
                ],
            ],
            $result['list'][0]['listeners']
        );
    }

    public function testGetEventListenersSkipsSelfRegisteredListener(): void
    {
        $collector = new EventCollectorTestable();
        $context = new EventCollectorContext();
        $collector->setContextManager($this->createContextManager($collector, $context));

        $listenerProvider = $this->createMock(ListenerProviderInterface::class);
        $listeners = new PrioritizedListeners();
        $listeners->add([$collector, 'onEvent']);
        $listeners->add([new EventCollectorBusinessListener(), 'onEvent']);
        $wildcards = new PrioritizedListeners();
        $wildcards->add([$collector, 'onEvent']);
        $wildcards->add([new EventCollectorWildcardListener(), 'onEvent']);
        $listenerProvider->method('getListenersForEvent')->willReturn($listeners);
        $listenerProvider->method('getListenersForWildcard')->willReturn($wildcards);
        $collector->setListenerProvider($listenerProvider);

        $collector->onEvent(new EventCollectorTestEvent());

        $this->assertSame(
            [
                [
                    'name' => EventCollectorBusinessListener::class . '::onEvent',
                    'type' => 'business',
                ],
                [
                    'name' => EventCollectorWildcardListener::class . '::onEvent',
                    'type' => 'business',
                ],
            ],
            $collector->collect()['list'][0]['listeners']
        );
    }

    public function testOnEventSkipsWhenLimitIsReached(): void
    {
        $collector = new EventCollectorTestable();
        $context = new EventCollectorContext();
        $context->events = [
            ['event' => 'AlreadyCaptured', 'source' => [], 'listeners' => []],
        ];
        $collector->setContextManager($this->createContextManager($collector, $context));
        $collector->setLimit(1);

        $collector->onEvent(new EventCollectorTestEvent());

        $this->assertCount(1, $collector->collect()['list']);
        $this->assertSame('AlreadyCaptured', $collector->collect()['list'][0]['event']);
    }

    public function testJsonSerializeReturnsEmptyArray(): void
    {
        $this->assertSame([], (new EventCollector())->jsonSerialize());
    }

    public function testBootIsNoOpOnCollectorWrapper(): void
    {
        $collector = new EventCollectorTestable();
        $collector->setListenerProvider($this->createMock(ListenerProviderInterface::class));
        $collector->boot();
        $this->assertTrue(true);
    }

    protected function createContextManager(EventCollectorTestable $collector, EventCollectorContext $context): ContextManagerInterface
    {
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $contextManager->method('getContext')->with($collector)->willReturn($context);

        return $contextManager;
    }
}

class EventCollectorTestable extends EventCollector
{
    public function setContextManager(ContextManagerInterface $contextManager): void
    {
        $this->contextManager = $contextManager;
    }

    public function setListenerProvider(ListenerProviderInterface $listenerProvider): void
    {
        $this->listenerProvider = $listenerProvider;
    }

    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }
}

class EventCollectorTestEvent
{
    public string $foo = 'alpha';

    public int $bar = 7;
}

class EventCollectorObservabilityListener implements ObservabilityProbe
{
    public function onEvent(object $event): void
    {
    }
}

class EventCollectorBusinessListener
{
    public function onEvent(object $event): void
    {
    }
}

class EventCollectorWildcardListener
{
    public function onEvent(object $event): void
    {
    }
}
