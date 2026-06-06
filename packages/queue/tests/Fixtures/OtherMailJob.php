<?php

declare(strict_types=1);

namespace Switon\Queue\Tests\Fixtures;

use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;

#[Queued('emails')]
class OtherMailJob implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
