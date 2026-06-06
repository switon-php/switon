<?php

declare(strict_types=1);

namespace Switon\Queue\Tests\Fixtures;

use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;

#[Queued('emails')]
class MailJob implements RunnerInterface
{
    public static int $handled = 0;

    public function run(mixed $payload): mixed
    {
        self::$handled++;
        return null;
    }
}
