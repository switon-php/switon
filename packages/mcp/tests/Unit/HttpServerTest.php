<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Http\ResponseInterface;
use Switon\Mcp\HttpServer;
use Switon\Mcp\ProtocolHandlerInterface;
use Switon\Mcp\ToolsInterface;
use Switon\Mcp\Transport\HttpTransportInterface;
use Switon\Mcp\Tests\TestCase;

#[CoversClass(HttpServer::class)]
class HttpServerTest extends TestCase
{
    public function testHandlePipesTransportThroughProtocolHandler(): void
    {
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
        $rpc = ['jsonrpc' => '2.0', 'id' => 1, 'result' => []];
        $response = $this->createStub(ResponseInterface::class);
        $tools = $this->createStub(ToolsInterface::class);

        $protocolHandler = $this->createMock(ProtocolHandlerInterface::class);
        $protocolHandler->expects($this->once())
            ->method('handle')
            ->with($request, $tools)
            ->willReturn($rpc);

        $httpTransport = $this->createMock(HttpTransportInterface::class);
        $httpTransport->expects($this->once())->method('read')->willReturn($request);
        $httpTransport->expects($this->once())->method('write')->with($request, $rpc)->willReturn($response);

        $server = $this->make(HttpServer::class, [
            'httpTransport' => $httpTransport,
            'protocolHandler' => $protocolHandler,
            'tools' => $tools,
        ]);

        $this->assertSame($response, $server->handle());
    }

    public function testTerminateDelegatesToTransport(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $httpTransport = $this->createMock(HttpTransportInterface::class);
        $httpTransport->expects($this->once())->method('terminate')->willReturn($response);

        $server = $this->make(HttpServer::class, [
            'httpTransport' => $httpTransport,
            'protocolHandler' => $this->createStub(ProtocolHandlerInterface::class),
            'tools' => $this->createStub(ToolsInterface::class),
        ]);

        $this->assertSame($response, $server->terminate());
    }
}
