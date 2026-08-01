<?php

declare(strict_types=1);

namespace Switon\Mcp\Transport;

/**
 * MCP stdio I/O: newline-delimited JSON-RPC over stdin/stdout.
 *
 * @see \Switon\Mcp\Transport\StdioTransport
 * @see \Switon\Mcp\StdioServerInterface
 */
interface StdioTransportInterface
{
    /** @return array<string, mixed>|null Next JSON-RPC request, or null when stdin closes. */
    public function read(): ?array;

    /**
     * @param array<string, mixed> $request Incoming JSON-RPC request.
     * @param array<string, mixed> $response Outgoing JSON-RPC; empty skips stdout (notification).
     */
    public function write(array $request, array $response): void;
}
