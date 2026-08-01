<?php

declare(strict_types=1);

namespace Switon\Mcp;

use Switon\Http\ResponseInterface;

/**
 * App-facing Streamable HTTP MCP entry: transport I/O + protocol + tools.
 *
 * @see \Switon\Mcp\HttpServer
 * @see \Switon\Mcp\StdioServerInterface
 */
interface HttpServerInterface
{
    public function handle(): ResponseInterface;

    public function terminate(): ResponseInterface;
}
