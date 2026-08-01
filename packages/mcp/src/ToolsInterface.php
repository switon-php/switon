<?php

declare(strict_types=1);

namespace Switon\Mcp;

/**
 * MCP tool catalog for list and invoke.
 *
 * @see \Switon\Mcp\Tools
 */
interface ToolsInterface
{
    /** @return array<string, ToolInterface> Name to tool (null-schema entries omitted). */
    public function all(): array;

    /** Published tool, or null when missing or {@see ToolInterface::schema()} is null. */
    public function get(string $name): ?ToolInterface;

    /** Whether a name is already in the catalog map (including null-schema entries). */
    public function has(string $name): bool;

    /** Inserts or overwrites a tool by name. */
    public function add(string $name, ToolInterface $tool): void;
}
