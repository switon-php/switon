<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\EventDispatcher\EventDispatcherInterface;
use stdClass;
use Switon\Eventing\ListenerProviderInterface;
use Switon\McpClient\Client;
use Switon\McpClient\ClientInfo;
use Switon\McpClient\Event\ToolCalled;
use Switon\McpClient\Event\ToolCalling;
use Switon\McpClient\Exception;
use Switon\McpClient\Exception\RpcException;
use Switon\McpClient\Exception\TransportException;
use Switon\McpClient\Tests\TestCase;
use Switon\McpClient\Tests\Unit\Transport\StubTransport;
use Switon\Testing\EventDispatcher as TestEventDispatcher;

#[CoversClass(Client::class)]
class ClientTest extends TestCase
{
    public function testCallToolReturnsCancelledWhenAborted(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();

        $result = $this->makeClient($transport)->callTool(
            'ping',
            shouldAbort: static fn (): bool => true,
            raw: false,
        );

        $this->assertSame(['error' => 'cancelled', 'cancelled' => true], $result);
        $this->assertNotContains('tools/call', array_column($transport->sent, 'method'));
    }

    public function testCallToolReturnsARawCancelledResult(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();

        $result = $this->makeClient($transport)->callTool('ping', shouldAbort: static fn (): bool => true);

        $this->assertTrue($result['isError']);
        $this->assertSame('cancelled', $result['content'][0]['text']);
    }

    public function testBriefAndNotificationHandlerAreAvailableBeforeConnect(): void
    {
        $transport = new StubTransport();
        $client = $this->makeClient($transport);
        $seen = false;
        $client->onNotification(static function () use (&$seen): void {
            $seen = true;
        });

        $this->assertSame('', $client->brief());
        $this->assertFalse($seen);
    }

    public function testCallToolDispatchesLifecycleEvents(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['content' => [['type' => 'text', 'text' => 'pong']]],
        ];

        $client = $this->makeClient($transport);
        $dispatcher = $this->eventDispatcher();

        $result = $client->callTool("ping", ['echo' => 'hi']);

        $this->assertSame('pong', $result['content'][0]['text']);

        $callingEvents = $dispatcher->getEventsByClass(ToolCalling::class);
        $this->assertCount(1, $callingEvents);
        $this->assertSame('ping', $callingEvents[0]->name);
        $this->assertSame(['echo' => 'hi'], $callingEvents[0]->arguments);

        $calledEvents = $dispatcher->getEventsByClass(ToolCalled::class);
        $this->assertCount(1, $calledEvents);
        $this->assertSame('ping', $calledEvents[0]->name);
        $this->assertSame(['echo' => 'hi'], $calledEvents[0]->arguments);
        $this->assertSame($result, $calledEvents[0]->result);
    }

    public function testCallToolReturnsMutatedResultFromToolCalledEvent(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['content' => [['type' => 'text', 'text' => 'pong']]],
        ];

        $client = $this->makeClient($transport);

        $this->container->get(ListenerProviderInterface::class)->on(
            null,
            static function (ToolCalled $event): void {
                $event->result = ['content' => [['type' => 'text', 'text' => 'mutated']]];
            },
        );

        $this->assertSame('mutated', $client->callTool("ping")['content'][0]['text']);
    }

    public function testCallToolStripsTrailingDotKeysAfterToolCalling(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['content' => []],
        ];

        $client = $this->makeClient($transport);
        $dispatcher = $this->eventDispatcher();
        $arguments = [
            '.keep' => 'user',
            'x' => 1,
            '.caption' => 'Ping.',
            '.agent' => 'ignored-for-mcp',
        ];

        $client->callTool('ping', $arguments);

        $callingEvents = $dispatcher->getEventsByClass(ToolCalling::class);
        $this->assertSame($arguments, $callingEvents[0]->arguments);
        $calledEvents = $dispatcher->getEventsByClass(ToolCalled::class);
        $this->assertSame($arguments, $calledEvents[0]->arguments);
        $this->assertSame([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 2,
            'params' => ['name' => 'ping', 'arguments' => ['.keep' => 'user', 'x' => 1]],
        ], $transport->sent[2]);
    }

    public function testCallToolSendsJsonRpcRequestWithParams(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['content' => []],
        ];

        $client = $this->makeClient($transport);
        $client->callTool("ping", ['key' => 'value']);

        $this->assertSame([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 2,
            'params' => ['name' => 'ping', 'arguments' => ['key' => 'value']],
        ], $transport->sent[2]);
    }

    public function testCallToolSendsEmptyArgumentsAsAnObject(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => []]];

        $this->makeClient($transport)->callTool('ping');

        $arguments = $transport->sent[2]['params']['arguments'];
        $this->assertInstanceOf(stdClass::class, $arguments);
        $this->assertSame([], (array) $arguments);
    }

    public function testInitializeSendsNotificationWithoutId(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'serverInfo' => ['name' => 'demo', 'version' => '1.0.0'],
            ],
        ];

        $info = ClientInfo::of('my-app', '2.0.0');
        $client = $this->makeClient($transport, $info);
        $client->initialize();

        $this->assertSame('2025-03-26', $transport->sent[0]['params']['protocolVersion']);
        $this->assertSame('2024-11-05', $info->negotiatedProtocol);
        $this->assertSame('my-app', $transport->sent[0]['params']['clientInfo']['name']);
        $this->assertSame('2.0.0', $transport->sent[0]['params']['clientInfo']['version']);
        $this->assertSame([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], $transport->sent[1]);
    }

    public function testInitializeKeepsWhateverProtocolTheServerReturns(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['protocolVersion' => ' 2025-11-25 '],
        ];

        $info = ClientInfo::of('test-client');
        $client = $this->makeClient($transport, $info);
        $client->initialize();

        $this->assertSame('2025-11-25', $info->negotiatedProtocol);
        $this->assertSame('notifications/initialized', $transport->sent[1]['method']);
        $this->assertSame(0, $transport->closeCount);
    }

    public function testInitializeRejectsAMissingProtocolVersion(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['serverInfo' => ['name' => 'demo']],
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('MCP server did not return a protocol version.');

        try {
            $this->makeClient($transport)->initialize();
        } finally {
            $this->assertGreaterThanOrEqual(1, $transport->closeCount);
            $this->assertCount(1, $transport->sent);
        }
    }

    public function testListToolsFollowsNextCursorUntilItRepeats(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'tools' => [['name' => 'one']],
                'nextCursor' => 'page-2',
            ],
        ];
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => [
                'tools' => [['name' => 'two']],
                'nextCursor' => 'page-2',
            ],
        ];

        $tools = $this->makeClient($transport)->listTools();

        $this->assertSame(['one', 'two'], array_keys($tools));
        $lists = array_values(array_filter(
            $transport->sent,
            static fn (array $message): bool => ($message['method'] ?? null) === 'tools/list',
        ));
        $this->assertCount(2, $lists);
        $this->assertArrayNotHasKey('params', $lists[0]);
        $this->assertSame(['cursor' => 'page-2'], $lists[1]['params']);
    }

    public function testCallToolRaisesRpcExceptionOnJsonRpcError(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'error' => ['code' => -32601, 'message' => 'Method not found'],
        ];

        $client = $this->makeClient($transport);

        $this->expectException(RpcException::class);
        $this->expectExceptionMessage('MCP JSON-RPC error -32601: Method not found');

        $client->callTool("ping");
    }

    public function testLazilyInitializesOnFirstCall(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => []]];

        $client = $this->makeClient($transport);
        $this->assertSame([], $transport->sent, 'Constructing a client must not eagerly initialize.');
        $this->assertSame(0, $transport->openCount, 'Transport must not be opened at construction.');

        $client->callTool("ping");
        $this->assertSame(1, $transport->openCount, 'First call must open the transport once.');
        $this->assertSame('initialize', $transport->sent[0]['method']);
        $this->assertSame('notifications/initialized', $transport->sent[1]['method']);
        $this->assertSame('tools/call', $transport->sent[2]['method']);
    }

    public function testIdlePingProbeRunsAfterThreshold(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'first']]]];
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 3, 'result' => []];
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 4, 'result' => ['content' => [['type' => 'text', 'text' => 'second']]]];

        $client = $this->makeClient($transport, idleThreshold: 0.0);
        $client->callTool("ping");
        $sentMethods = array_column($transport->sent, 'method');
        $this->assertNotContains('ping', $sentMethods, 'First call (cold start) must not idle-ping.');

        $client->callTool("ping");
        $sentMethods = array_column($transport->sent, 'method');
        $this->assertSame('ping', $sentMethods[count($sentMethods) - 2], 'Idle ping must precede the real request after the threshold.');
        $this->assertSame('tools/call', $sentMethods[count($sentMethods) - 1]);
    }

    public function testDoesNotResendWhenTransportDropsMidCall(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = null;
        $transport->responses[] = $this->initializeResponse(3);
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 4, 'result' => ['content' => [['type' => 'text', 'text' => 'again']]]];

        $client = $this->makeClient($transport);
        try {
            $client->callTool('ping');
            $this->fail('A dropped response must not be sent again.');
        } catch (TransportException) {
        }

        $this->assertSame(1, $transport->openCount);
        $this->assertSame(1, $transport->closeCount);
        $this->assertSame(
            ['initialize', 'notifications/initialized', 'tools/call'],
            array_column($transport->sent, 'method'),
        );

        $this->assertSame('again', $client->callTool('ping')['content'][0]['text']);
        $this->assertSame(2, $transport->openCount, 'The next call opens a new connection.');
    }

    public function testReconnectsWhenIdlePingFails(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'first']]]];
        $transport->responses[] = null; // idle ping EOF -> triggers reconnect
        $transport->responses[] = $this->initializeResponse(4);
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 5, 'result' => ['content' => [['type' => 'text', 'text' => 'second']]]];

        $client = $this->makeClient($transport, idleThreshold: 0.0);
        $client->callTool("ping");

        $this->assertSame('second', $client->callTool("ping")['content'][0]['text']);
        $this->assertSame(2, $transport->openCount);
        $this->assertSame(1, $transport->closeCount);
    }

    public function testIdlePingRpcErrorStillSendsTheCall(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'first']]]];
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 3, 'error' => ['code' => -32601, 'message' => 'Method not found']];
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 4, 'result' => ['content' => [['type' => 'text', 'text' => 'second']]]];
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 5, 'result' => ['content' => [['type' => 'text', 'text' => 'third']]]];

        $client = $this->makeClient($transport, idleThreshold: 0.0);
        $this->assertSame('first', $client->callTool('ping')['content'][0]['text']);
        $this->assertSame('second', $client->callTool('ping')['content'][0]['text']);
        $this->assertSame('third', $client->callTool('ping')['content'][0]['text']);
        $this->assertSame(
            ['initialize', 'notifications/initialized', 'tools/call', 'ping', 'tools/call', 'tools/call'],
            array_column($transport->sent, 'method'),
        );
    }

    public function testListToolsRefetchesAfterTheConnectionDrops(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'ping']]]];
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['tools' => [['name' => 'ping']]]];
        $transport->responses[] = null;
        $transport->responses[] = $this->initializeResponse(5);
        $transport->responses[] = ['jsonrpc' => '2.0', 'id' => 6, 'result' => ['tools' => [['name' => 'pong']]]];

        $client = $this->makeClient($transport);

        $first = $client->listTools();
        $this->assertSame(['ping'], array_keys($first));
        $this->assertSame(['name' => 'ping'], $first['ping']->definition);
        $this->assertSame($first, $client->listTools(), 'Second listTools() must hit the cache, not the wire.');

        $toolsListCalls = count(array_filter(array_column($transport->sent, 'method'), static fn (string $m): bool => $m === 'tools/list'));
        $this->assertSame(1, $toolsListCalls, 'Cached listTools() must not re-request.');

        $refreshed = $client->listTools(refresh: true);
        $this->assertEquals($first, $refreshed, 'listTools(refresh: true) re-fetches.');
        $toolsListCalls = count(array_filter(array_column($transport->sent, 'method'), static fn (string $m): bool => $m === 'tools/list'));
        $this->assertSame(2, $toolsListCalls);

        try {
            $client->callTool('ping');
            $this->fail('A dropped call must surface to the caller.');
        } catch (TransportException) {
        }
        $after = $client->listTools();
        $this->assertSame(['pong'], array_keys($after));
        $this->assertSame(['name' => 'pong'], $after['pong']->definition, 'A dropped call clears the cached tool list.');

        $toolsListCalls = count(array_filter(array_column($transport->sent, 'method'), static fn (string $m): bool => $m === 'tools/list'));
        $this->assertSame(3, $toolsListCalls);
    }

    public function testListToolsIndexesByNameAndIgnoresUnusableEntries(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'tools' => [
                    ['name' => 'good', 'description' => 'ok'],
                    'not-an-array',
                    ['description' => 'no name'],
                    ['name' => ''],
                    ['name' => 123],
                    ['name' => 'good', 'description' => 'duplicate'],
                    ['name' => 'other'],
                ],
            ],
        ];

        $tools = $this->makeClient($transport)->listTools();

        $this->assertSame(['good', 'other'], array_keys($tools));
        $this->assertSame('duplicate', $tools['good']->definition['description']);
        $this->assertSame(['name' => 'other'], $tools['other']->definition);
    }

    public function testListToolsTreatsNonArrayToolsAsEmpty(): void
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['tools' => 'broken'],
        ];

        $this->assertSame([], $this->makeClient($transport)->listTools());
    }

    protected function initializeResponse(int $id = 1): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'serverInfo' => ['name' => 'demo', 'version' => '1.0.0'],
            ],
        ];
    }

    protected function eventDispatcher(): TestEventDispatcher
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);

        return $dispatcher;
    }

    protected function makeClient(
        StubTransport $transport,
        ?ClientInfo $clientInfo = null,
        float $idleThreshold = 30.0,
    ): Client {
        return $this->make(Client::class, [
            'server' => 'test',
            'transport' => $transport,
            'clientInfo' => $clientInfo ?? ClientInfo::of('test-client'),
            'idle' => $idleThreshold,
        ]);
    }

    public function testCallToolPresentJoinsTextBlocks(): void
    {
        $this->assertSame("hello\nworld", $this->callToolPresented([
            'content' => [
                ['type' => 'text', 'text' => 'hello'],
                ['type' => 'text', 'text' => 'world'],
            ],
        ]));
    }

    public function testCallToolPresentIgnoresNonTextBlocks(): void
    {
        $this->assertSame('only this', $this->callToolPresented([
            'content' => [
                ['type' => 'image', 'data' => '...'],
                ['type' => 'text', 'text' => 'only this'],
                'not-an-array',
                ['type' => 'text', 'text' => 123],
            ],
        ]));
    }

    public function testCallToolPresentFallsBackToTopLevelText(): void
    {
        $this->assertSame('top-level fallback', $this->callToolPresented([
            'content' => [['type' => 'image', 'data' => '...']],
            'text' => 'top-level fallback',
        ]));
    }

    public function testCallToolPresentReturnsWireResultWhenNoTextContent(): void
    {
        $result = ['content' => 'raw-string', 'isError' => false];

        $this->assertSame($result, $this->callToolPresented($result));
    }

    public function testCallToolPresentReturnsWireResultWhenOnlyNonTextBlocks(): void
    {
        $result = ['content' => [['type' => 'image', 'data' => '...']]];

        $this->assertSame($result, $this->callToolPresented($result));
    }

    public function testCallToolPresentWrapsIsError(): void
    {
        $this->assertSame(
            ['error' => 'tool failed'],
            $this->callToolPresented([
                'isError' => true,
                'content' => [['type' => 'text', 'text' => 'tool failed']],
            ]),
        );
    }

    /** @param array<string, mixed> $wireResult */
    protected function callToolPresented(array $wireResult): mixed
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => $wireResult,
        ];

        return $this->makeClient($transport)->callTool("ping", [], raw: false);
    }

    /** @param array<string, mixed> $wireResult */
    protected function callToolRaw(array $wireResult): array
    {
        $transport = new StubTransport();
        $transport->responses[] = $this->initializeResponse();
        $transport->responses[] = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => $wireResult,
        ];

        return $this->makeClient($transport)->callTool("ping", []);
    }
}
