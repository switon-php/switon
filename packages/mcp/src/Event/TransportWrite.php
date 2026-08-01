<?php

declare(strict_types=1);

namespace Switon\Mcp\Event;

use JsonSerializable;
use Switon\Core\Json;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Emitted when MCP transport writes a JSON-RPC response.
 *
 * Log category: <code>switon.mcp.transport.write</code>
 *
 * Guidance: Mutate <code>response</code> in listeners; transport {@see \Switon\Mcp\Transport\StdioTransportInterface::write()} / {@see \Switon\Mcp\Transport\HttpTransportInterface::write()} sends the event value.
 * Guidance: EventLogger serialize omits request/response bodies (method/id/bytes only).
 *
 * @see \Switon\Mcp\Transport\StdioTransportInterface::write()
 * @see \Switon\Mcp\Transport\HttpTransportInterface::write()
 * @see \Switon\Mcp\Event\TransportRead
 */
#[EventLevel(Severity::DEBUG)]
class TransportWrite implements JsonSerializable
{
    /**
     * @param array<string, mixed> $request Decoded JSON-RPC request payload.
     * @param array<string, mixed> $response JSON-RPC response envelope, or empty for notifications.
     */
    public function __construct(
        public array $request,
        public array $response,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'method' => $this->request['method'] ?? null,
            'id' => $this->request['id'] ?? null,
            'request_bytes' => strlen(Json::stringify($this->request)),
            'response_bytes' => strlen(Json::stringify($this->response)),
        ];
    }
}
