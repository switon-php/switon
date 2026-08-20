<?php

declare(strict_types=1);

namespace Switon\McpClient\Transport;

interface HttpTransportInterface extends TransportInterface
{
    public const SESSION_HEADER = 'Mcp-Session-Id';

    public const PROTOCOL_HEADER = 'MCP-Protocol-Version';

    /** Null before the server returns `SESSION_HEADER`. */
    public function getSessionId(): ?string;
}
