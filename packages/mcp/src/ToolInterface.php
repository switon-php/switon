<?php

declare(strict_types=1);

namespace Switon\Mcp;

/**
 * Tool served over MCP.
 *
 * Guidance: a `tools/call` request carries only `{name, arguments}`. Implement
 * {@see schema()} and {@see call()} for that wire shape.
 * Guidance: prefer extending {@see AbstractTool} and cache with
 * <code>$this->schema ??= new ToolSchema(...)</code> (`tools/list` / bind may call {@see schema()} more than once).
 *
 * Road-signs:
 * - Base: {@see AbstractTool}
 * - Registration: {@see Tools::$tools} (`name => class`) or {@see ToolDiscovery}
 * - Schema: {@see ToolSchema}; null disables the tool
 * - Argument defaults / required / scalar typing: {@see ArgumentBinderInterface}
 */
interface ToolInterface
{
    /** `null` disables this tool for the catalog. */
    public function schema(string $name): ?ToolSchema;

    /**
     * @param array<string, mixed> $arguments Bound by {@see ArgumentBinderInterface::bind()}
     *
     * @return mixed {@see ToolResult} for explicit success/failure; other values become a successful result
     */
    public function call(string $name, array $arguments): mixed;
}
