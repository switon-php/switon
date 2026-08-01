<?php

declare(strict_types=1);

namespace Switon\Mcp;

use Switon\Core\Attribute\Autowired;
use Switon\Http\ResponseInterface;
use Switon\Mcp\Transport\HttpTransportInterface;

/**
 * Thin Streamable HTTP MCP server: {@see HttpTransportInterface} + {@see ProtocolHandlerInterface} + {@see ToolsInterface}.
 *
 * @see \Switon\Mcp\HttpServerInterface
 */
class HttpServer implements HttpServerInterface
{
    #[Autowired] protected HttpTransportInterface $httpTransport;
    #[Autowired] protected ProtocolHandlerInterface $protocolHandler;
    #[Autowired] protected ToolsInterface $tools;

    public function handle(): ResponseInterface
    {
        $request = $this->httpTransport->read();

        return $this->httpTransport->write($request, $this->protocolHandler->handle($request, $this->tools));
    }

    public function terminate(): ResponseInterface
    {
        return $this->httpTransport->terminate();
    }
}
