<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Fixtures;

class OrderService
{
    public function statusOf(int $orderId): array
    {
        return ['orderId' => $orderId, 'status' => 'paid'];
    }
}
