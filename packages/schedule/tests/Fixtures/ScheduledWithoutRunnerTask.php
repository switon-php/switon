<?php

declare(strict_types=1);

namespace Switon\Schedule\Tests\Fixtures;

use Switon\Schedule\Attribute\Scheduled;

#[Scheduled('0 * * *')]
class ScheduledWithoutRunnerTask
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
