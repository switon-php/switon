<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Fixtures\Event\Sub;

use Stringable;

final class WithBuiltinContractEvent implements Stringable
{
    public function __toString(): string
    {
        return self::class;
    }
}
