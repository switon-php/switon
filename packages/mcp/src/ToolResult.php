<?php

declare(strict_types=1);

namespace Switon\Mcp;

use Throwable;

/**
 * One MCP tool call outcome: payload in {@see $content}, failure flag in {@see $isError}.
 *
 * Guidance: return this from {@see ToolInterface::call()} for an explicit success or failure.
 *
 * @see \Switon\Mcp\ProtocolHandler::call()
 */
class ToolResult
{
    public function __construct(
        public mixed $content = '',
        public bool $isError = false,
    ) {
        if ($this->content instanceof Throwable) {
            $this->isError = true;
        }
    }
}
