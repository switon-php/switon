<?php

declare(strict_types=1);

namespace Switon\Mcp;

/**
 * MCP tool-argument prep: defaults, required, then light scalar typing.
 *
 * Guidance: this is MCP-wire oriented (JSON-RPC arguments), not a full JSON Schema engine.
 *
 * @see ArgumentBinder
 */
interface ArgumentBinderInterface
{
    /**
     * Fill property defaults, reject missing required, coerce string/integer when declared.
     * Booleans are rejected. Other schema types are left unchanged.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function bind(ToolSchema $schema, array $arguments): array;
}
