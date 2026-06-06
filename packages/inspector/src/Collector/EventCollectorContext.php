<?php

declare(strict_types=1);

namespace Switon\Inspector\Collector;

/**
 * Request/coroutine-local storage for collected event inspector entries.
 *
 * @see \Switon\Inspector\Collector\EventCollector
 */
class EventCollectorContext
{
    /** @var list<array{event: string, source: list<string>, listeners: list<array{name: string, type: string}>}> */
    public array $events = [];
}
