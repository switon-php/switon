<?php

declare(strict_types=1);

namespace App\Billing\Areas;

use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

#[Scheduled('0 * * *')]
class SyncTask implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
