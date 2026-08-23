<?php

declare(strict_types=1);

namespace Switon\McpClient;

/**
 * Server id → client. Filled by {@see ClientDiscoveryInterface::discover()} via {@see add()}.
 *
 * Guidance: `add()` takes an mcp.yml entry; `get()` / `all()` return wired clients. Neither reads the file nor connects.
 */
interface ClientsInterface
{
    /**
     * @param array<string, mixed> $entry mcp.yml-shaped entry (`command` or `url`)
     */
    public function add(string $id, array $entry): void;

    /**
     * @throws \Switon\McpClient\Exception when the id is not registered
     */
    public function get(string $id): ClientInterface;

    /** @return array<string, ClientInterface> */
    public function all(): array;

    public function remove(string $id): void;
}
