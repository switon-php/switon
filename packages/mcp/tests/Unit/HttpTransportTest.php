<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Attribute\Autowired;
use Switon\Core\InputInterface;
use Switon\Core\StopFlow;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Http\Event\RequestReceived;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Http\ServerInterface;
use Switon\Mcp\Event\TransportRead;
use Switon\Mcp\Event\TransportWrite;
use Switon\Mcp\Transport\HttpTransport;
use Switon\Mcp\Transport\HttpTransportInterface;
use Switon\Mcp\Tests\TestCase;
use Switon\Testing\EventDispatcher as TestEventDispatcher;

#[CoversClass(HttpTransport::class)]
class HttpTransportTest extends TestCase
{
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ResponseInterface $response;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->container->set(InputInterface::class, RequestInterface::class);
    }

    public function testGetSessionIdReturnsNullWithoutHeader(): void
    {
        $this->seedRequest(rawBody: '', accept: 'application/json, text/event-stream');

        $this->assertNull($this->container->make(HttpTransport::class)->getSessionId());
    }

    public function testGetSessionIdReturnsHeaderValue(): void
    {
        $this->seedRequest(
            rawBody: '',
            accept: 'application/json, text/event-stream',
            sessionId: 'deadbeefdeadbeefdeadbeefdeadbeef',
        );

        $this->assertSame(
            'deadbeefdeadbeefdeadbeefdeadbeef',
            $this->container->make(HttpTransport::class)->getSessionId(),
        );
    }

    public function testWriteReturnsJsonRpcResponse(): void
    {
        $this->seedRequest(
            rawBody: '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            accept: 'application/json, text/event-stream',
        );

        $this->pipe([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [],
        ]);

        $this->assertSame(200, $this->response->getStatusCode());
        $this->assertSame('application/json', $this->response->getHeader('Content-Type'));
        $this->assertSame(
            '{"jsonrpc":"2.0","id":1,"result":[]}',
            $this->response->getContent(),
        );
    }

    public function testInitializeReturnsSessionIdHeader(): void
    {
        $this->seedRequest(
            rawBody: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26"}}',
            accept: 'application/json, text/event-stream',
        );

        $this->pipe([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['protocolVersion' => '2025-03-26'],
        ]);

        $sessionId = $this->response->getHeader(HttpTransportInterface::SESSION_HEADER);
        $this->assertMatchesRegularExpression('/^[0-9a-zA-Z]{20}$/', $sessionId);
    }

    public function testTerminateReturns204(): void
    {
        $this->seedRequest(
            rawBody: '',
            accept: 'application/json, text/event-stream',
            method: 'DELETE',
        );

        $this->container->make(HttpTransport::class)->terminate();

        $this->assertSame(204, $this->response->getStatusCode());
    }

    public function testWriteReturns202ForNotification(): void
    {
        $this->seedRequest(
            rawBody: '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            accept: 'application/json, text/event-stream',
        );

        $this->pipe([]);

        $this->assertSame(202, $this->response->getStatusCode());
        $this->assertNull($this->response->getContent());
    }

    public function testReadRejectsMissingAccept(): void
    {
        $this->seedRequest(
            rawBody: '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            accept: 'application/json',
        );

        try {
            $this->container->make(HttpTransport::class)->read();
            $this->fail('Expected StopFlow');
        } catch (StopFlow) {
        }

        $this->assertSame(406, $this->response->getStatusCode());
        $body = json_decode((string) $this->response->getContent(), true);
        $this->assertNull($body['id']);
        $this->assertSame(-32600, $body['error']['code']);
    }

    public function testReadDispatchesTransportRead(): void
    {
        $dispatcher = $this->testEventDispatcher();
        $dispatcher->clear();

        $this->seedRequest(
            rawBody: '{"jsonrpc":"2.0","id":4,"method":"ping"}',
            accept: 'application/json, text/event-stream',
        );

        $payload = $this->container->make(HttpTransport::class)->read();

        $this->assertSame('ping', $payload['method']);
        $this->assertCount(1, $dispatcher->getEventsByClass(TransportRead::class));
    }

    public function testReadRejectsEmptyBody(): void
    {
        $this->seedRequest(rawBody: '', accept: 'application/json, text/event-stream');

        $this->expectException(\Switon\Http\Exception\BadRequestException::class);
        $this->container->make(HttpTransport::class)->read();
    }

    public function testReadRejectsInvalidJson(): void
    {
        $this->seedRequest(rawBody: '{', accept: 'application/json, text/event-stream');

        $this->expectException(\Switon\Http\Exception\BadRequestException::class);
        $this->container->make(HttpTransport::class)->read();
    }

    public function testReadRejectsNonObjectJson(): void
    {
        $this->seedRequest(rawBody: 'true', accept: 'application/json, text/event-stream');

        $this->expectException(\Switon\Http\Exception\BadRequestException::class);
        $this->expectExceptionMessage('MCP request body must be a JSON object.');
        $this->container->make(HttpTransport::class)->read();
    }

    public function testReadRejectsInvalidOrigin(): void
    {
        $this->seedRequest(
            rawBody: '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            accept: 'application/json, text/event-stream',
            origin: 'http://evil.example',
        );

        $this->expectException(\Switon\Http\Exception\ForbiddenException::class);
        $this->container->make(HttpTransport::class)->read();
    }

    public function testReadAllowsConfiguredOrigin(): void
    {
        $this->seedRequest(
            rawBody: '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            accept: 'application/json, text/event-stream',
            origin: 'http://evil.example',
        );

        $transport = $this->container->make(HttpTransport::class, [
            'allowedOrigins' => ['http://evil.example'],
        ]);
        $request = $transport->read();
        $transport->write($request, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [],
        ]);

        $this->assertSame(200, $this->response->getStatusCode());
    }

    public function testReadAllowsSameOriginAsRequestHost(): void
    {
        $this->seedRequest(
            rawBody: '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            accept: 'application/json, text/event-stream',
            origin: 'http://localhost',
        );

        $this->pipe([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [],
        ]);

        $this->assertSame(200, $this->response->getStatusCode());
    }

    public function testReadAndWriteDispatchTransportEventsForPing(): void
    {
        $dispatcher = $this->testEventDispatcher();
        $dispatcher->clear();

        $this->seedRequest(
            rawBody: '{"jsonrpc":"2.0","id":3,"method":"ping","params":[]}',
            accept: 'application/json, text/event-stream',
        );

        $this->pipe([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => [],
        ]);

        $this->assertCount(1, $dispatcher->getEventsByClass(TransportRead::class));
        $this->assertSame('ping', $dispatcher->getEventsByClass(TransportRead::class)[0]->request['method']);
        $this->assertCount(1, $dispatcher->getEventsByClass(TransportWrite::class));
        $this->assertArrayHasKey('result', $dispatcher->getEventsByClass(TransportWrite::class)[0]->response);
    }

    /**
     * @param array<string, mixed> $rpcResponse
     */
    protected function pipe(array $rpcResponse): void
    {
        $transport = $this->container->make(HttpTransport::class);
        $request = $transport->read();
        $transport->write($request, $rpcResponse);
    }

    protected function testEventDispatcher(): TestEventDispatcher
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);

        return $dispatcher;
    }

    protected function seedRequest(
        string $rawBody,
        string $accept,
        string $method = 'POST',
        ?string $sessionId = null,
        ?string $origin = null,
    ): void {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => '/mcp',
            'HTTP_ACCEPT' => $accept,
            'HTTP_HOST' => 'localhost',
            'REQUEST_SCHEME' => 'http',
        ];
        if ($sessionId !== null) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        }
        if ($origin !== null) {
            $server['HTTP_ORIGIN'] = $origin;
        }

        $this->request->onRequestReceived(new RequestReceived(
            sender: $this->createStub(ServerInterface::class),
            GET: [],
            POST: [],
            SERVER: $server,
            RAW_BODY: $rawBody,
            COOKIE: [],
            FILES: [],
        ));
    }
}
