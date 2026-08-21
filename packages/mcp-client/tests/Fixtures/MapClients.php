<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Fixtures;

use Switon\McpClient\ClientInterface;
use Switon\McpClient\ClientsInterface;
use Switon\McpClient\Exception;

/**
 * In-memory client map that records the last `get()` id.
 */
class MapClients implements ClientsInterface
{
    public string $requested = '';

    /** @param array<string, ClientInterface> $clients */
    public function __construct(protected array $clients)
    {
    }

    public function add(string $id, ClientInterface $client): void
    {
        $this->clients[$id] = $client;
    }

    public function get(string $id): ClientInterface
    {
        $this->requested = $id;
        if (!isset($this->clients[$id])) {
            Exception::raise('Unknown MCP server "{server}".', ['server' => $id]);
        }

        return $this->clients[$id];
    }

    public function all(): array
    {
        return $this->clients;
    }

    public function remove(string $id): void
    {
        unset($this->clients[$id]);
    }
}
