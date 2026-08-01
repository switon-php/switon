<?php

declare(strict_types=1);

namespace Switon\Mcp;

/**
 * App-facing stdio MCP entry: transport I/O + protocol + tools.
 *
 * @see \Switon\Mcp\StdioServer
 * @see \Switon\Mcp\HttpServerInterface
 */
interface StdioServerInterface
{
    /** Runs until stdin closes. */
    public function serve(): void;
}
