<?php

declare(strict_types=1);

namespace Switon\Mcp;

use Switon\Core\Attribute\Autowired;
use Switon\Mcp\Transport\StdioTransportInterface;

/**
 * Thin stdio MCP server: {@see StdioTransportInterface} + {@see ProtocolHandlerInterface} + {@see ToolsInterface}.
 *
 * @see \Switon\Mcp\StdioServerInterface
 */
class StdioServer implements StdioServerInterface
{
    #[Autowired] protected StdioTransportInterface $stdioTransport;
    #[Autowired] protected ProtocolHandlerInterface $protocolHandler;
    #[Autowired] protected ToolsInterface $tools;

    public function serve(): void
    {
        while (($request = $this->stdioTransport->read()) !== null) {
            $this->stdioTransport->write($request, $this->protocolHandler->handle($request, $this->tools));
        }
    }
}
