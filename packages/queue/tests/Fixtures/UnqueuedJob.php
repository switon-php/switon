<?php

declare(strict_types=1);

namespace Switon\Queue\Tests\Fixtures;

use Switon\Core\RunnerInterface;

class UnqueuedJob implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
