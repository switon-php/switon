<?php

declare(strict_types=1);

namespace Switon\Dict\Tests\Fixtures;

use Switon\Dict\Dict;

class OrderStatus extends Dict
{
    public static function typeCode(): ?string
    {
        return 'order.status';
    }
}
