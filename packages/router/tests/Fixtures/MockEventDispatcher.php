<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Fixtures;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Mock event dispatcher implementation for router tests.
 */
class MockEventDispatcher implements EventDispatcherInterface
{
    protected array $dispatchedEvents = [];

    public function dispatch(object $event): object
    {
        $this->dispatchedEvents[] = $event;
        return $event;
    }

    // Test helper methods
    public function getDispatchedEvents(): array
    {
        return $this->dispatchedEvents;
    }

    public function clearDispatchedEvents(): void
    {
        $this->dispatchedEvents = [];
    }
}
