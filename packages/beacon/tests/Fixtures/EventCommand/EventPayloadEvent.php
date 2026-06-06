<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Fixtures\EventCommand;

class EventPayloadEvent
{
    public string $id = 'evt';
    public int $count = 1;
}
