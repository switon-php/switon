<?php

declare(strict_types=1);

namespace Switon\McpClient;

class Clients implements ClientsInterface
{
    /** @var array<string, ClientInterface> */
    protected array $clients = [];

    public function add(string $id, ClientInterface $client): void
    {
        $this->clients[$id] = $client;
    }

    public function get(string $id): ClientInterface
    {
        if (isset($this->clients[$id])) {
            return $this->clients[$id];
        }

        Exception::raise('Unknown MCP server "{server}".', ['server' => $id]);
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
