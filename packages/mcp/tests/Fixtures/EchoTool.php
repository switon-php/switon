<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Fixtures;

use Switon\Mcp\AbstractTool;
use Switon\Mcp\ToolSchema;

/** Fixture {@see \Switon\Mcp\ToolInterface} for Tools `$tools` registration tests. */
class EchoTool extends AbstractTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Echo path argument.',
            properties: [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to echo.',
                ],
            ],
            required: ['path'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        return ['name' => $name, 'path' => $arguments['path'] ?? null];
    }
}
