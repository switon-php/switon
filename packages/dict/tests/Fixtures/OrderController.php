<?php

declare(strict_types=1);

namespace Switon\Dict\Tests\Fixtures;

final class OrderController
{
    public function updateAction(OrderStatus $status): void
    {
    }

    public function nullableAction(?OrderStatus $status): void
    {
    }
}
