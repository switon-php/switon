<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Fixtures\ClassCommand\Event;

class SampleEvent
{
    public function id(): string
    {
        return 'evt';
    }
}
