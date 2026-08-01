<?php

declare(strict_types=1);

namespace Switon\Mcp\Exception;

use Switon\Mcp\Exception as McpException;

/**
 * Use when <code>tools/call</code> names a tool missing from the registry.
 */
class ToolNotFoundException extends McpException
{
}
