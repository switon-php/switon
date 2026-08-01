<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Fixtures;

use Switon\Mcp\AbstractTool;
use Switon\Mcp\ToolSchema;

/** Fixture whose {@see schema()} returns null (omitted from catalog). */
class DisabledTool extends AbstractTool
{
    public function schema(string $name): ?ToolSchema
    {
        return null;
    }

    public function call(string $name, array $arguments): mixed
    {
        return [];
    }
}
