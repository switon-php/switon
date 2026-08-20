<?php

declare(strict_types=1);

namespace Switon\McpClient\Exception;

/**
 * MCP wire transfer aborted locally (HTTP progress callback or stdio poll).
 */
class AbortedTransportException extends TransportException
{
}
