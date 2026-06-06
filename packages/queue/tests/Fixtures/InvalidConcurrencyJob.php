<?php

declare(strict_types=1);

namespace Switon\Queue\Tests\Fixtures;

use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;

#[Queued('invalid-concurrency', concurrency: 0)]
class InvalidConcurrencyJob implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
