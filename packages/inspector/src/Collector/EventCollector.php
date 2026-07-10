<?php

declare(strict_types=1);

namespace Switon\Inspector\Collector;

use JsonSerializable;
use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;
use Switon\Eventing\Attribute\EventListener;
use Switon\Eventing\EventLoggerInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Eventing\ObservabilityProbe;
use Switon\Logging\Event\LoggerLogged;

use function array_keys;
use function count;
use function is_array;
use function is_object;

/**
 * Collects dispatched events and resolved listeners for inspector output.
 *
 * @see \Switon\Core\CollectorInterface
 * @see \Switon\Eventing\EventDispatcher
 */
class EventCollector implements CollectorInterface, ObservabilityProbe, ContextAware, JsonSerializable
{
    #[Autowired] protected ContextManagerInterface $contextManager;
    #[Autowired] protected ListenerProviderInterface $listenerProvider;
    #[Autowired] protected EventLoggerInterface $eventLogger;

    /** Maximum events retained per request for inspector output. */
    #[Autowired] protected int $limit = 1000;

    public function getContext(): EventCollectorContext
    {
        return $this->contextManager->getContext($this);
    }

    public function boot(): void
    {
        $this->listenerProvider->register($this);
    }

    #[EventListener] public function onEvent(object $event): void
    {
        if ($event instanceof LoggerLogged) {
            return;
        }

        $context = $this->getContext();

        if (count($context->events) >= $this->limit) {
            return;
        }

        $context->events[] = [
            'event' => $event::class,
            'source' => array_keys(get_object_vars($event)),
            'listeners' => $this->getEventListeners($event),
        ];
    }

    /**
     * @return list<array{name: string, type: string}>
     */
    protected function getEventListeners(object $event): array
    {
        $listeners = [];
        foreach ($this->listenerProvider->getListenersForEvent($event) as $listener) {
            $this->appendListener($listeners, $listener);
        }

        foreach ($this->listenerProvider->getListenersForSender($event) as $listener) {
            $this->appendListener($listeners, $listener);
        }

        foreach ($this->listenerProvider->getListenersForWildcard() as $listener) {
            $this->appendListener($listeners, $listener);
        }

        return $listeners;
    }

    /**
     * @param list<array{name: string, type: string}> $listeners
     */
    protected function appendListener(array &$listeners, mixed $listener): void
    {
        if (is_array($listener) && isset($listener[0], $listener[1])) {
            [$listenerObject, $listenerMethod] = $listener;

            if (is_object($listenerObject) && $listenerObject === $this && $listenerMethod === 'onEvent') {
                return;
            }

            if (is_object($listenerObject)) {
                $type = $listenerObject instanceof ObservabilityProbe ? 'observability' : 'business';
                $listeners[] = [
                    'name' => $listenerObject::class . '::' . $listenerMethod,
                    'type' => $type,
                ];
            }

            return;
        }

        $listeners[] = [
            'name' => 'Closure',
            'type' => 'business',
        ];
    }

    public function collect(): array
    {
        return [
            'list' => $this->getContext()->events,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [];
    }
}
