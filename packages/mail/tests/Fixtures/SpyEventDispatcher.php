<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Fixtures;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
final class SpyEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
