<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Switon\HttpClient\Exception\AbortedException;
use Switon\HttpClient\HttpClientInterface;
use Switon\HttpClient\Request;
use Switon\HttpClient\RequestOptions;
use Switon\HttpClient\Response;
use Switon\McpClient\ClientInfo;
use Switon\McpClient\Event\TransportRead;
use Switon\McpClient\Exception;
use Switon\McpClient\Exception\AbortedTransportException;
use Switon\McpClient\Exception\DecodeException;
use Switon\McpClient\Tests\TestCase;
use Switon\McpClient\Transport\HttpTransport;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Throwable;

#[CoversClass(HttpTransport::class)]
class HttpTransportTest extends TestCase
{
    public function testJsonResponseStoresSessionAndProtocolHeaderFollowsNegotiation(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(200, '{"jsonrpc":"2.0","id":1,"result":{}}', [
            'Content-Type' => 'application/json',
            'Mcp-Session-Id' => 'sess-1',
        ]);
        $http->responses[] = $this->response(200, '{"jsonrpc":"2.0","id":2,"result":{"ok":true}}', [
            'Content-Type' => 'application/json',
        ]);

        $info = ClientInfo::of('switon-agent');
        $transport = $this->transport($http, $info);

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], $transport->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
            static fn (): bool => false,
        ));
        $this->assertSame('sess-1', $transport->getSessionId());
        $this->assertArrayNotHasKey('MCP-Protocol-Version', $http->calls[0]['headers']);

        $info->negotiatedProtocol = '2025-03-26';
        $this->assertSame(['ok' => true], $transport->send(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
            static fn (): bool => false,
        )['result']);
        $this->assertSame('sess-1', $http->calls[1]['headers']['Mcp-Session-Id']);
        $this->assertSame('2025-03-26', $http->calls[1]['headers']['MCP-Protocol-Version']);
    }

    public function testEventStreamReturnsMatchingIdAndForwardsNotifications(): void
    {
        $body = "event: message\n"
            . "data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/message\",\"params\":{}}\n"
            . "\n"
            . "data: {\"jsonrpc\":\"2.0\",\"id\":\"7\",\"result\":{\"content\":[]}}\n"
            . "\n";
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(200, $body, [
            'Content-Type' => 'text/event-stream',
        ]);
        $seen = [];

        $result = $this->transport($http)->send(
            ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call'],
            static fn (): bool => false,
            static function (array $message) use (&$seen): void {
                $seen[] = $message['method'] ?? null;
            },
        );

        $this->assertSame(['notifications/message'], $seen);
        $this->assertSame(['content' => []], $result['result']);
    }

    public function testSendRejectsAResponseWhoseIdDoesNotMatch(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(200, '{"jsonrpc":"2.0","id":9,"result":{}}', [
            'Content-Type' => 'application/json',
        ]);

        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('did not include a JSON-RPC message');
        $this->transport($http)->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            static fn (): bool => false,
        );
    }

    public function testSendDoesNotPostWhenAlreadyAborted(): void
    {
        $http = new FakeHttpClient();
        $transport = $this->transport($http);

        try {
            $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], static fn (): bool => true);
            $this->fail('Abort must raise before the POST.');
        } catch (AbortedTransportException) {
            $this->assertSame([], $http->calls);
        }
    }

    public function testAcceptedResponseWithoutJsonRpcFails(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(202, '');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('without a JSON-RPC response');

        $this->transport($http)->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            static fn (): bool => false,
        );
    }

    public function testHttpErrorWithoutJsonRpcFails(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(401, 'nope', ['Content-Type' => 'text/plain']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('status 401');

        $this->transport($http)->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            static fn (): bool => false,
        );
    }

    public function testHttpErrorWithJsonRpcErrorIsReturned(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(400, '{"jsonrpc":"2.0","id":1,"error":{"code":-32600,"message":"bad"}}', [
            'Content-Type' => 'application/json',
        ]);

        $result = $this->transport($http)->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
            static fn (): bool => false,
        );

        $this->assertSame(-32600, $result['error']['code']);
    }

    public function testInvalidJsonRaisesDecodeException(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(200, '{', ['Content-Type' => 'application/json']);

        $this->expectException(DecodeException::class);

        $this->transport($http)->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            static fn (): bool => false,
        );
    }

    public function testOpenAndCloseDoNothingWithoutASession(): void
    {
        $http = new FakeHttpClient();
        $transport = $this->transport($http);

        $transport->open();
        $transport->close();

        $this->assertNull($transport->getSessionId());
        $this->assertSame([], $http->calls);
    }

    public function testSendMapsClientAbortToTransportAbort(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = new class ('aborted') extends AbortedException {
            public function __construct(string $message)
            {
                parent::__construct($message);
            }
        };

        $this->expectException(AbortedTransportException::class);
        $this->expectExceptionMessage('aborted');

        $this->transport($http)->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            static fn (): bool => false,
        );
    }

    public function testNotificationReturnsNull(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(200, '{"jsonrpc":"2.0","result":{}}', [
            'Content-Type' => 'application/json',
        ]);

        $this->assertNull($this->transport($http)->send(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            static fn (): bool => false,
        ));
    }

    public function testNotificationOnHttpErrorWithoutRpcErrorFails(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(500, '{"jsonrpc":"2.0","result":{}}', [
            'Content-Type' => 'application/json',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('status 500');

        $this->transport($http)->send(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            static fn (): bool => false,
        );
    }

    public function testRpcErrorWithoutAnIdIsReturned(): void
    {
        $dispatcher = $this->eventDispatcher();
        $dispatcher->clear();
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(200, '{"jsonrpc":"2.0","error":{"code":-32603,"message":"nope"}}', [
            'Content-Type' => 'application/json',
        ]);

        $result = $this->transport($http)->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            static fn (): bool => false,
        );

        $this->assertSame(-32603, $result['error']['code']);
        $this->assertCount(1, $dispatcher->getEventsByClass(TransportRead::class));
    }

    public function testHttpErrorWithADifferentIdFails(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(500, '{"jsonrpc":"2.0","id":9,"result":{}}', [
            'Content-Type' => 'application/json',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('status 500');

        $this->transport($http)->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            static fn (): bool => false,
        );
    }

    public function testRequestHeadersKeepOnlyNonEmptyStrings(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(200, '{"jsonrpc":"2.0","result":{}}');
        $transport = $this->make(HttpTransport::class, [
            'httpClient' => $http,
            'url' => 'https://example.test/mcp',
            'headers' => [
                'X-Token' => 'abc',
                'Skip' => '',
                'Bad' => 1,
            ],
        ]);

        $transport->send(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            static fn (): bool => false,
        );

        $this->assertSame('abc', $http->calls[0]['headers']['X-Token']);
        $this->assertArrayNotHasKey('Skip', $http->calls[0]['headers']);
        $this->assertArrayNotHasKey('Bad', $http->calls[0]['headers']);
    }

    public function testJsonScalarIsRejected(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(200, 'true', ['Content-Type' => 'application/json']);

        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('must decode to an object');

        $this->transport($http)->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            static fn (): bool => false,
        );
    }

    public function testEventStreamSkipsCommentsAndKeepsATrailingEvent(): void
    {
        $body = ": keep-alive\n"
            . "data:\n"
            . "\n"
            . "data: {\n"
            . "\n"
            . "data: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"ok\":true}}";
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(200, $body, [
            'Content-Type' => 'text/event-stream',
        ]);

        $result = $this->transport($http)->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            static fn (): bool => false,
        );

        $this->assertTrue($result['result']['ok']);
    }

    public function testCloseDeletesSessionAndSwallowsFailure(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->response(200, '{"jsonrpc":"2.0","id":1,"result":{}}', [
            'Content-Type' => 'application/json',
            'Mcp-Session-Id' => 'sess-9',
        ]);
        $http->responses[] = new RuntimeException('delete failed');

        $transport = $this->transport($http);
        $transport->send(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
            static fn (): bool => false,
        );
        $transport->close();

        $this->assertSame('DELETE', $http->calls[1]['method']);
        $this->assertSame('sess-9', $http->calls[1]['headers']['Mcp-Session-Id']);
        $this->assertNull($transport->getSessionId());
    }

    private function transport(FakeHttpClient $http, ?ClientInfo $clientInfo = null): HttpTransport
    {
        return $this->make(HttpTransport::class, [
            'httpClient' => $http,
            'url' => 'https://example.test/mcp',
            'clientInfo' => $clientInfo,
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function response(int $status, string $body, array $headers = []): Response
    {
        $lines = ["HTTP/1.1 $status OK"];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return new Response(new Request('POST', 'https://example.test/mcp', null, [], []), $lines, $body);
    }

    private function eventDispatcher(): TestEventDispatcher
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);

        return $dispatcher;
    }
}

/**
 * @internal
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<Response|Throwable> */
    public array $responses = [];

    /** @var list<array{method: string, body: mixed, headers: array<string, mixed>}> */
    public array $calls = [];

    public function fetch(
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null,
        array $options = [],
    ): array {
        unset($url, $method, $headers, $body, $options);

        throw new RuntimeException('unused');
    }

    public function request(
        string $method,
        string|array $url,
        null|string|array $body = null,
        array $headers = [],
        ?RequestOptions $options = null,
    ): Response {
        unset($url, $options);
        $this->calls[] = ['method' => $method, 'body' => $body, 'headers' => $headers];
        $next = array_shift($this->responses);
        if ($next instanceof Throwable) {
            throw $next;
        }
        if (!$next instanceof Response) {
            throw new RuntimeException('FakeHttpClient has no response queued.');
        }

        return $next;
    }

    public function head(string|array $url, array $headers = [], ?RequestOptions $options = null): Response
    {
        unset($url, $headers, $options);

        throw new RuntimeException('unused');
    }

    public function get(string|array $url, array $headers = [], ?RequestOptions $options = null): Response
    {
        unset($url, $headers, $options);

        throw new RuntimeException('unused');
    }

    public function post(string|array $url, string|array $body = [], array $headers = [], ?RequestOptions $options = null): Response
    {
        unset($url, $body, $headers, $options);

        throw new RuntimeException('unused');
    }

    public function delete(string|array $url, array $headers = [], ?RequestOptions $options = null): Response
    {
        unset($url, $headers, $options);

        throw new RuntimeException('unused');
    }

    public function put(string|array $url, string|array $body = [], array $headers = [], ?RequestOptions $options = null): Response
    {
        unset($url, $body, $headers, $options);

        throw new RuntimeException('unused');
    }

    public function patch(string|array $url, string|array $body = [], array $headers = [], ?RequestOptions $options = null): Response
    {
        unset($url, $body, $headers, $options);

        throw new RuntimeException('unused');
    }
}
