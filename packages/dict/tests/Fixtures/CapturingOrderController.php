<?php

declare(strict_types=1);

namespace Switon\Dict\Tests\Fixtures;

class CapturingOrderController extends OrderController
{
    public ?OrderStatus $receivedStatus = null;

    public function updateAction(OrderStatus $status): void
    {
        $this->receivedStatus = $status;
    }

    public function nullableAction(?OrderStatus $status): void
    {
        $this->receivedStatus = $status;
    }
}
