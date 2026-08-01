<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Fixtures;

use Switon\Mcp\AbstractTool;
use Switon\Mcp\ToolSchema;

/** Fixture that echoes the bound arguments bag. */
class PassthroughTool extends AbstractTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Pass arguments through.',
            properties: [
                'command' => ['type' => 'string'],
                'args' => ['type' => 'array'],
            ],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        return $arguments;
    }
}
