<?php

declare(strict_types=1);

namespace Switon\Schedule\Tests\Fixtures;

use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

/** Intentionally invalid: five fields only (for registration test). */
#[Scheduled('* * * * *')]
final class FiveFieldTask implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
