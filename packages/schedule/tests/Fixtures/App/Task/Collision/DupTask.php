<?php

declare(strict_types=1);

namespace App\Task\Collision;

use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

#[Scheduled('0 * * *')]
final class DupTask implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
