<?php

declare(strict_types=1);

namespace Switon\Schedule\Tests\Fixtures;

use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

#[Scheduled('0 * * *')]
class FooBarTask implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
