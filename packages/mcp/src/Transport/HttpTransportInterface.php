<?php

declare(strict_types=1);

namespace Switon\Mcp\Transport;

use Switon\Http\ResponseInterface;

/**
 * Streamable HTTP MCP I/O: read JSON-RPC from the request, write the response.
 *
 * Prefer {@see \Switon\Mcp\HttpServerInterface} from app controllers; this type is raw HTTP I/O.
 *
 * @see \Switon\Mcp\Transport\HttpTransport
 */
interface HttpTransportInterface
{
    public const SESSION_HEADER = 'Mcp-Session-Id';

    /** Client session id from `SESSION_HEADER`, or null when absent. */
    public function getSessionId(): ?string;

    /**
     * Validates Origin/Accept, then returns the decoded JSON-RPC request body.
     *
     * @return array<string, mixed>
     */
    public function read(): array;

    /**
     * @param array<string, mixed> $request Incoming JSON-RPC request.
     * @param array<string, mixed> $response Outgoing JSON-RPC; empty sets HTTP 202 (notification).
     */
    public function write(array $request, array $response): ResponseInterface;

    /** Terminate the session named in `SESSION_HEADER`. */
    public function terminate(): ResponseInterface;
}
