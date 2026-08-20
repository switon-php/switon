<?php

declare(strict_types=1);

namespace Switon\McpClient;

/**
 * Server id → client. Filled by {@see ClientDiscoveryInterface::discover()}.
 *
 * Guidance: `get()` does not read the file and does not connect.
 */
interface ClientsInterface
{
    public function add(string $id, ClientInterface $client): void;

    /**
     * @throws \Switon\McpClient\Exception when the id is not registered
     */
    public function get(string $id): ClientInterface;

    /** @return array<string, ClientInterface> */
    public function all(): array;

    public function remove(string $id): void;
}
