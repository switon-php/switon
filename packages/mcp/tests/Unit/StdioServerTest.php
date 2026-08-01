<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Mcp\ProtocolHandlerInterface;
use Switon\Mcp\StdioServer;
use Switon\Mcp\ToolsInterface;
use Switon\Mcp\Transport\StdioTransportInterface;
use Switon\Mcp\Tests\TestCase;

#[CoversClass(StdioServer::class)]
class StdioServerTest extends TestCase
{
    public function testServeHandlesRequestsUntilReadReturnsNull(): void
    {
        $request = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'];
        $response = ['jsonrpc' => '2.0', 'id' => 2, 'result' => []];

        $tools = $this->createStub(ToolsInterface::class);
        $protocolHandler = $this->createMock(ProtocolHandlerInterface::class);
        $protocolHandler->expects($this->once())
            ->method('handle')
            ->with($request, $tools)
            ->willReturn($response);

        $stdioTransport = $this->createMock(StdioTransportInterface::class);
        $stdioTransport->expects($this->exactly(2))->method('read')->willReturnOnConsecutiveCalls($request, null);
        $stdioTransport->expects($this->once())->method('write')->with($request, $response);

        $server = $this->make(StdioServer::class, [
            'stdioTransport' => $stdioTransport,
            'protocolHandler' => $protocolHandler,
            'tools' => $tools,
        ]);
        $server->serve();
    }
}
