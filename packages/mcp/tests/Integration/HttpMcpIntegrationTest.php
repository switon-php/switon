<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Integration;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\InputInterface;
use Switon\Http\Event\RequestReceived;
use Switon\Http\RequestHandlerInterface;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Http\ServerInterface;
use Switon\Mcp\Tests\Fixtures\PingTool;
use Switon\Mcp\Tests\Fixtures\TestMcpController;
use Switon\Mcp\Tests\TestCase;
use Switon\Mcp\ToolsInterface;
use Switon\Routing\RouteRegistrarInterface;

use function json_decode;

#[AllowMockObjectsWithoutExpectations]
class HttpMcpIntegrationTest extends TestCase
{
    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->container->set(InputInterface::class, RequestInterface::class);

        $server = $this->createMock(ServerInterface::class);
        $server->method('write')->willReturn(true);
        $this->container->remove(ServerInterface::class);
        $this->container->replace(ServerInterface::class, $server);

        $this->container->set(PingTool::class, new PingTool());
    }

    public function testPostMcpInitializeReturnsJsonRpcResult(): void
    {
        $this->registerMcpRoute();

        $body = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26"}}';
        $request = $this->container->get(RequestInterface::class);
        $request->onRequestReceived(new RequestReceived(
            sender: $this->container->get(ServerInterface::class),
            GET: [],
            POST: [],
            SERVER: [
                'REQUEST_URI' => '/mcp',
                'REQUEST_METHOD' => 'POST',
                'HTTP_ACCEPT' => 'application/json, text/event-stream',
                'HTTP_HOST' => 'localhost',
                'REQUEST_SCHEME' => 'http',
            ],
            RAW_BODY: $body,
            COOKIE: [],
            FILES: [],
        ));

        $handler = $this->container->get(RequestHandlerInterface::class);
        $handler->boot();
        $handler->handle();

        $content = (string)$this->container->get(ResponseInterface::class)->getContent();
        $decoded = json_decode($content, true);

        $this->assertSame('2025-03-26', $decoded['result']['protocolVersion']);
        $this->assertSame(1, $decoded['id']);
    }

    public function testPostMcpToolsCallPingReturnsOk(): void
    {
        $this->registerMcpRoute();

        $body = '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"ping","arguments":{}}}';
        $request = $this->container->get(RequestInterface::class);
        $request->onRequestReceived(new RequestReceived(
            sender: $this->container->get(ServerInterface::class),
            GET: [],
            POST: [],
            SERVER: [
                'REQUEST_URI' => '/mcp',
                'REQUEST_METHOD' => 'POST',
                'HTTP_ACCEPT' => 'application/json, text/event-stream',
                'HTTP_HOST' => 'localhost',
                'REQUEST_SCHEME' => 'http',
            ],
            RAW_BODY: $body,
            COOKIE: [],
            FILES: [],
        ));

        $handler = $this->container->get(RequestHandlerInterface::class);
        $handler->boot();
        $handler->handle();

        $decoded = json_decode((string)$this->container->get(ResponseInterface::class)->getContent(), true);

        $this->assertSame('pong', $decoded['result']['content'][0]['text']);
    }

    protected function registerMcpRoute(): void
    {
        $this->container->get(ToolsInterface::class)->add(
            'ping',
            $this->container->get(PingTool::class),
        );

        $this->container->set(TestMcpController::class, $this->container->make(TestMcpController::class));
        $this->container->get(RouteRegistrarInterface::class)->registerClass(TestMcpController::class);
    }
}
