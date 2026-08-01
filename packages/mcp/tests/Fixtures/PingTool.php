<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Fixtures;

use Switon\Mcp\AbstractTool;
use Switon\Mcp\ToolSchema;

/** Fixture {@see \Switon\Mcp\ToolInterface} health check. */
class PingTool extends AbstractTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(description: 'Health check; returns pong.');
    }

    public function call(string $name, array $arguments): mixed
    {
        return 'pong';
    }
}
