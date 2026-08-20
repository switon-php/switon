<?php

declare(strict_types=1);

namespace Switon\McpClient;

/**
 * Reads `~/.switon/mcp.yml` into {@see ClientsInterface}.
 *
 * Guidance: `discover()` is the only reread, does not open a transport, keeps a client whose entry is unchanged, and rejects a server id that contains `_`.
 *
 * @see \Switon\McpClient\ClientDiscovery
 */
interface ClientDiscoveryInterface
{
    public function discover(): void;
}
