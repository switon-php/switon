<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit\Collectors;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\ContextManagerInterface;
use Switon\Eventing\EventDispatcherContext;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Eventing\ObservabilityProbe;
use Switon\Eventing\PrioritizedListeners;
use Switon\Inspector\Collector\ListenerStatsCollector;
use Switon\Testing\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ListenerStatsCollectorTest extends TestCase
{
    public function testCollectMergesRegistryAndDispatchCountsAndClassifiesWildcards(): void
    {
        $provider = new ListenerStatsCollectorFakeProvider();
        $provider->observabilityListenerRegistry = [
            'Obs\\Probe::onEvent' => true,
            'Obs\\NeverCalled::onEvent' => true,
        ];
        $provider->businessListenerRegistry = [
            'Biz\\Listener::handle' => true,
            'Biz\\NeverCalled::handle' => true,
        ];
        $provider->wildcards = [
            [[new ListenerStatsCollectorObservabilityWildcard(), 'onEvent']],
            [[new ListenerStatsCollectorBusinessWildcard(), 'onEvent']],
            [static function (object $event): void {
            }],
        ];

        $dispatcherContext = new EventDispatcherContext();
        $dispatcherContext->observabilityCallCounts = [
            'Obs\\Probe::onEvent' => 2,
            'Obs\\RuntimeOnly::onEvent' => 1,
        ];
        $dispatcherContext->businessCallCounts = [
            'Biz\\Listener::handle' => 3,
            'Biz\\RuntimeOnly::handle' => 1,
        ];

        $contextManager = $this->createMock(ContextManagerInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $contextManager->method('getContext')
            ->with($eventDispatcher)
            ->willReturn($dispatcherContext);

        $collector = $this->make(ListenerStatsCollector::class, [
            'listenerProvider' => $provider,
            'eventDispatcher' => $eventDispatcher,
            'contextManager' => $contextManager,
        ]);

        $result = $collector->collect();

        $this->assertSame(
            [
                [
                    'name' => ListenerStatsCollectorObservabilityWildcard::class . '::onEvent',
                    'type' => 'observability',
                ],
                [
                    'name' => ListenerStatsCollectorBusinessWildcard::class . '::onEvent',
                    'type' => 'business',
                ],
                [
                    'name' => 'Closure',
                    'type' => 'business',
                ],
            ],
            $result['wildcards']
        );

        $this->assertSame(2, $result['observability']['Obs\\Probe::onEvent']);
        $this->assertSame(1, $result['observability']['Obs\\RuntimeOnly::onEvent']);
        $this->assertSame(0, $result['observability']['Obs\\NeverCalled::onEvent']);

        $this->assertSame(3, $result['business']['Biz\\Listener::handle']);
        $this->assertSame(1, $result['business']['Biz\\RuntimeOnly::handle']);
        $this->assertSame(0, $result['business']['Biz\\NeverCalled::handle']);
    }

    public function testJsonSerializeReturnsEmptyArray(): void
    {
        $collector = new ListenerStatsCollector();
        $this->assertSame([], $collector->jsonSerialize());
    }

    public function testBootIsNoOp(): void
    {
        $collector = new ListenerStatsCollector();
        $collector->boot();
        $this->assertTrue(true);
    }
}

class ListenerStatsCollectorFakeProvider implements ListenerProviderInterface
{
    /** @var array<string, true> */
    public array $observabilityListenerRegistry = [];

    /** @var array<string, true> */
    public array $businessListenerRegistry = [];

    /** @var array<int, array<int, callable>> */
    public array $wildcards = [];

    public function on(?object $sender, callable $handler, int $priority = 0): void
    {
    }

    public function register(string|object $listener): void
    {
    }

    public function getListenersForEvent(object $event): PrioritizedListeners
    {
        return new PrioritizedListeners();
    }

    public function getListenersForWildcard(): PrioritizedListeners
    {
        $prioritized = new PrioritizedListeners();
        foreach ($this->wildcards as $priority => $bucket) {
            foreach ($bucket as $listener) {
                $prioritized->add($listener, $priority);
            }
        }

        return $prioritized;
    }

    public function getListenersForSender(object $event): PrioritizedListeners
    {
        return new PrioritizedListeners();
    }

    public function getListeners(): array
    {
        return [];
    }

    public function getObservabilityListenerRegistry(): array
    {
        return $this->observabilityListenerRegistry;
    }

    public function getBusinessListenerRegistry(): array
    {
        return $this->businessListenerRegistry;
    }
}

class ListenerStatsCollectorObservabilityWildcard implements ObservabilityProbe
{
    public function onEvent(object $event): void
    {
    }
}

class ListenerStatsCollectorBusinessWildcard
{
    public function onEvent(object $event): void
    {
    }
}
