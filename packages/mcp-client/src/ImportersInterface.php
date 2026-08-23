<?php

declare(strict_types=1);

namespace Switon\McpClient;

use Switon\McpClient\Importer\ImporterInterface;

/**
 * Registry of MCP config importers (path => class, same shape as `extra.switon.mcp.importers`).
 *
 * Guidance: {@see all()} is the built-in path map; {@see import()} resolves one `$path` and runs its class.
 * `mcp-client:import` walks {@see all()}. For a one-off file, call {@see ImporterInterface::import()} on a class. Boot does not write `mcp.yml`.
 *
 * @see Importers
 * @see ImporterInterface
 */
interface ImportersInterface
{
    /**
     * @return array<string, class-string<ImporterInterface>> path => importer class
     */
    public function all(): array;

    /** @param class-string<ImporterInterface> $class */
    public function set(string $path, string $class): void;

    public function has(string $path): bool;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function import(string $path): array;
}
