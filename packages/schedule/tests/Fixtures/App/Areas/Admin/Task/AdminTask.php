<?php

declare(strict_types=1);

namespace App\Areas\Admin\Task;

use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

#[Scheduled('0 * * *')]
final class AdminTask implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
