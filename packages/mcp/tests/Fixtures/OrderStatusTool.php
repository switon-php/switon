<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Fixtures;

use Switon\Mcp\AbstractTool;
use Switon\Mcp\ToolSchema;

/** Fixture {@see \Switon\Mcp\ToolInterface} for order status lookup tests. */
class OrderStatusTool extends AbstractTool
{
    public function __construct(protected OrderService $orders)
    {
    }

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Look up order status by orderId',
            properties: [
                'orderId' => ['type' => 'integer'],
            ],
            required: ['orderId'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        return $this->orders->statusOf($arguments['orderId']);
    }
}
