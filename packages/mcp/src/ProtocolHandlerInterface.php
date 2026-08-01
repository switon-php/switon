<?php

declare(strict_types=1);

namespace Switon\Mcp;

/**
 * Handles one inbound MCP JSON-RPC request.
 *
 * Guidance: Mutate {@see Event\ToolCalled::$result} or {@see Event\TransportWrite::$response} in listeners.
 * Guidance: callers pass the tool catalog; this type stays protocol-only.
 *
 * @see \Switon\Mcp\ProtocolHandler
 */
interface ProtocolHandlerInterface
{
    /**
     * @param array<string, mixed> $request Decoded JSON-RPC request payload.
     *
     * @return array<string, mixed>
     */
    public function handle(array $request, ToolsInterface $tools): array;
}
