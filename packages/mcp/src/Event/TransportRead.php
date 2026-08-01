<?php

declare(strict_types=1);

namespace Switon\Mcp\Event;

use JsonSerializable;
use Switon\Core\Json;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Emitted when MCP transport reads a JSON-RPC request.
 *
 * Log category: <code>switon.mcp.transport.read</code>
 *
 * Guidance: EventLogger serialize omits request body (method/id/bytes only).
 *
 * @see \Switon\Mcp\Transport\StdioTransportInterface::read()
 * @see \Switon\Mcp\Transport\HttpTransportInterface::read()
 * @see \Switon\Mcp\Event\TransportWrite
 */
#[EventLevel(Severity::DEBUG)]
class TransportRead implements JsonSerializable
{
    /**
     * @param array<string, mixed> $request Decoded JSON-RPC request payload.
     */
    public function __construct(
        public array $request,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'method' => $this->request['method'] ?? null,
            'id' => $this->request['id'] ?? null,
            'request_bytes' => strlen(Json::stringify($this->request)),
        ];
    }
}
