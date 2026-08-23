<?php

declare(strict_types=1);

namespace Switon\McpClient;

/**
 * Reads `~/.switon/mcp.yml` and {@see ClientsInterface::add()}'s each entry.
 *
 * Guidance: `discover()` is the only reread and does not open a transport. Unchanged entries keep their client inside {@see Clients}.
 *
 * @see \Switon\McpClient\ClientDiscovery
 */
interface ClientDiscoveryInterface
{
    public function discover(): void;
}
