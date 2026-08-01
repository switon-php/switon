<?php

declare(strict_types=1);

namespace Switon\Mcp\Exception;

use Switon\Mcp\Exception as McpException;

/**
 * Use when the JSON-RPC method is not supported by this handler.
 */
class UnsupportedMethodException extends McpException
{
}
