<?php

declare(strict_types=1);

namespace Switon\McpClient\Importer;

/**
 * One MCP config format → catalog entries. Call {@see import()} with any path; no default path.
 *
 * @see \Switon\McpClient\ImportersInterface
 */
interface ImporterInterface
{
    /**
     * @return array<string, array<string, mixed>> server id => mcp.yml-shaped entry
     */
    public function import(string $path): array;
}
