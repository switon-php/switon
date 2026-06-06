<?php

declare(strict_types=1);

namespace Switon\Queue\Tests\Fixtures;

use RuntimeException;
use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;

#[Queued('failing')]
class FailingJob implements RunnerInterface
{
    public static int $handled = 0;

    public function run(mixed $payload): mixed
    {
        self::$handled++;
        throw new RuntimeException('fail');
    }
}
