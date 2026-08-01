<?php

declare(strict_types=1);

namespace Switon\Mcp\Exception;

use Switon\Mcp\Exception as McpException;

/**
 * Use when <code>tools/call</code> params fail JSON-RPC parameter validation.
 */
class InvalidParamsException extends McpException
{
}
