<?php

declare(strict_types=1);

namespace Switon\Schedule\Tests\Fixtures;

use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

#[Scheduled('0 * * *', lockTtl: 86_401)]
class InvalidLockTtlTooHighTask implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
