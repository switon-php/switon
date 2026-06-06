<?php

declare(strict_types=1);

namespace Switon\Inspector\Collector;

use JsonSerializable;
use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;
use Switon\Core\ContextManagerInterface;
use Switon\Eventing\EventDispatcherContext;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Eventing\ObservabilityProbe;

use function is_array;
use function is_object;

/**
 * Collects wildcard listeners and invocation counts for inspector output.
 *
 * @see \Switon\Core\CollectorInterface
 * @see \Switon\Eventing\EventDispatcher
 * @see \Switon\Eventing\ListenerProvider
 */
class ListenerStatsCollector implements CollectorInterface, JsonSerializable
{
    #[Autowired] protected ListenerProviderInterface $listenerProvider;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected ContextManagerInterface $contextManager;

    public function boot(): void
    {
    }

    public function collect(): array
    {
        return [
            'wildcards' => $this->getWildcardListeners(),
            'observability' => $this->mergeListenerStats(
                $this->getObservabilityListenerRegistry(),
                $this->getObservabilityEventDispatchCount()
            ),
            'business' => $this->mergeListenerStats(
                $this->getBusinessListenerRegistry(),
                $this->getBusinessEventDispatchCount()
            ),
        ];
    }

    /**
     * @return array<string, true>
     */
    protected function getObservabilityListenerRegistry(): array
    {
        return $this->listenerProvider->getObservabilityListenerRegistry();
    }

    /**
     * @return array<string, true>
     */
    protected function getBusinessListenerRegistry(): array
    {
        return $this->listenerProvider->getBusinessListenerRegistry();
    }

    protected function getEventDispatcherContext(): EventDispatcherContext
    {
        return $this->contextManager->getContext($this->eventDispatcher);
    }

    /**
     * @return array<string, int>
     */
    protected function getObservabilityEventDispatchCount(): array
    {
        return $this->getEventDispatcherContext()->observabilityCallCounts;
    }

    /**
     * @return array<string, int>
     */
    protected function getBusinessEventDispatchCount(): array
    {
        return $this->getEventDispatcherContext()->businessCallCounts;
    }

    /**
     * @param array<string, true> $registry
     * @param array<string, int> $dispatchCount
     *
     * @return array<string, int>
     */
    protected function mergeListenerStats(array $registry, array $dispatchCount): array
    {
        $merged = [];
        foreach ($dispatchCount as $key => $count) {
            $merged[$key] = $count;
        }
        foreach ($registry as $key => $value) {
            if (!isset($merged[$key])) {
                $merged[$key] = 0;
            }
        }
        return $merged;
    }

    /**
     * @return list<array{name: string, type: string}>
     */
    protected function getWildcardListeners(): array
    {
        $wildcards = [];
        foreach ($this->listenerProvider->getListenersForWildcard() as $listener) {
            if (is_array($listener) && isset($listener[0], $listener[1])) {
                [$listenerObject, $listenerMethod] = $listener;
                if (is_object($listenerObject)) {
                    $type = $listenerObject instanceof ObservabilityProbe ? 'observability' : 'business';
                    $wildcards[] = [
                        'name' => $listenerObject::class . '::' . $listenerMethod,
                        'type' => $type,
                    ];
                }
            } else {
                $wildcards[] = [
                    'name' => 'Closure',
                    'type' => 'business',
                ];
            }
        }
        return $wildcards;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [];
    }
}
