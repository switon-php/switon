<?php

declare(strict_types=1);

namespace Switon\McpClient\Exception;

use Switon\McpClient\Exception as McpClientException;

/**
 * Use when the MCP transport cannot complete the exchange.
 */
class TransportException extends McpClientException
{
}
