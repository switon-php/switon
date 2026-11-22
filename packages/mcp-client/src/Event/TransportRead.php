<?php

declare(strict_types=1);

namespace Switon\McpClient\Event;

use JsonSerializable;
use Switon\Core\Json;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * One inbound JSON-RPC message.
 *
 * Log category: `switon.mcp.client.transport.read`
 */
#[EventLevel(Severity::DEBUG)]
class TransportRead implements JsonSerializable
{
    /**
     * @param array<string, mixed> $message
     */
    public function __construct(
        public array $message,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'method' => $this->message['method'] ?? null,
            'id' => $this->message['id'] ?? null,
            'message_bytes' => strlen(Json::stringify($this->message)),
        ];
    }
}
