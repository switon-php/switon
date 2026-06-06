<?php

declare(strict_types=1);

namespace Switon\Queue\Tests\Fixtures;

use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;

#[Queued('')]
class EmptyQueueNameJob implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
