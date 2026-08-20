<?php

declare(strict_types=1);

namespace Switon\McpClient\Exception;

use Switon\McpClient\Exception as McpClientException;

/**
 * Use when an encoded MCP JSON-RPC message cannot be parsed or decoded.
 */
class DecodeException extends McpClientException
{
}
