<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Fixtures;

use Closure;
use Switon\Mcp\ToolInterface;
use Switon\Mcp\ToolSchema;

/** Fixture {@see ToolInterface} backed by a callable for ProtocolHandler edge-case tests. */
class CallbackTool implements ToolInterface
{
    /**
     * @param Closure(array<string, mixed>): mixed $callback
     */
    public function __construct(
        protected ToolSchema $schema,
        protected Closure $callback,
    ) {
    }

    public function schema(string $name): ToolSchema
    {
        return $this->schema;
    }

    public function call(string $name, array $arguments): mixed
    {
        return ($this->callback)($arguments);
    }
}
