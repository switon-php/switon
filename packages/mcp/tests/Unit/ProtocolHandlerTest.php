<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Mcp\Event\ToolCalled;
use Switon\Mcp\Event\ToolCalling;
use Switon\Mcp\NotificationsInterface;
use Switon\Mcp\ProtocolHandler;
use Switon\Mcp\ServerInfo;
use Switon\Mcp\Tests\Fixtures\CallbackTool;
use Switon\Mcp\Tests\Fixtures\DisabledTool;
use Switon\Mcp\Tests\Fixtures\OrderService;
use Switon\Mcp\Tests\Fixtures\OrderStatusTool;
use Switon\Mcp\Tests\Fixtures\PassthroughTool;
use Switon\Mcp\Tests\Fixtures\RethrowingProtocolHandler;
use Switon\Mcp\Tests\TestCase;
use Switon\Mcp\ToolInterface;
use Switon\Mcp\ToolResult;
use Switon\Mcp\ToolSchema;
use Switon\Mcp\Tools;
use Switon\Mcp\ToolsInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;

#[CoversClass(ProtocolHandler::class)]
class ProtocolHandlerTest extends TestCase
{
    protected ToolsInterface $tools;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->container->set(OrderService::class, new OrderService());
        $this->container->set(
            OrderStatusTool::class,
            new OrderStatusTool($this->container->get(OrderService::class)),
        );
    }

    public function testInitializeEchoesSupportedClientProtocolVersion(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26'],
        ]);

        $this->assertSame('2025-03-26', $response['result']['protocolVersion']);
    }

    public function testPingReturnsEmptyResult(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'ping',
            'params' => [],
        ]);

        $this->assertSame(9, $response['id']);
        $this->assertSame([], $response['result']);
    }

    public function testInitializeReturnsServerInfo(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]);

        $this->assertSame(1, $response['id']);
        $this->assertSame('switon', $response['result']['serverInfo']['name']);
        $this->assertSame('2024-11-05', $response['result']['protocolVersion']);
        $this->assertFalse($response['result']['capabilities']['tools']['listChanged']);
        $this->assertArrayNotHasKey('instructions', $response['result']);
    }

    public function testInitializeIncludesInstructionsFromServerDescription(): void
    {
        $this->tools = $this->make(Tools::class);
        $protocolHandler = $this->make(ProtocolHandler::class, [
            'serverInfo' => $this->make(ServerInfo::class, ['description' => 'Use tools for order lookups only.']),
        ]);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]);

        $this->assertSame('Use tools for order lookups only.', $response['result']['instructions']);
    }

    public function testToolsListReturnsRegisteredTools(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $this->assertCount(1, $response['result']['tools']);
        $this->assertSame('order_get_status', $response['result']['tools'][0]['name']);
        $this->assertSame('integer', $response['result']['tools'][0]['inputSchema']['properties']['orderId']['type']);
    }

    public function testToolsCallInvokesRegisteredHandler(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'order_get_status',
                'arguments' => ['orderId' => 42],
            ],
        ]);

        $this->assertSame('{"orderId":42,"status":"paid"}', $response['result']['content'][0]['text']);
        $this->assertArrayNotHasKey('structuredContent', $response['result']);
    }

    public function testToolsCallPassesArgumentsToArrayInputMethod(): void
    {
        $this->container->set(PassthroughTool::class, new PassthroughTool());
        $protocolHandler = $this->makeProtocolHandlerWithDiscovered(['array_shell_run' => PassthroughTool::class]);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => [
                'name' => 'array_shell_run',
                'arguments' => [
                    'command' => 'grep',
                    'args' => ['-r', 'pattern', '.'],
                ],
            ],
        ]);

        $this->assertSame(
            '{"command":"grep","args":["-r","pattern","."]}',
            $response['result']['content'][0]['text'],
        );
    }

    public function testHandleReturnsInvalidRequestWhenMethodIsMissing(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 4,
            'params' => [],
        ]);

        $this->assertSame(-32600, $response['error']['code']);
        $this->assertSame('method is required', $response['error']['message']);
    }

    public function testHandleReturnsInvalidRequestWhenMethodIsNotString(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 1,
            'params' => [],
        ]);

        $this->assertSame(-32600, $response['error']['code']);
        $this->assertSame('method must be a string', $response['error']['message']);
    }

    public function testHandleReturnsInvalidParamsWhenParamsIsNotObject(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'ping',
            'params' => 'bad',
        ]);

        $this->assertSame(-32602, $response['error']['code']);
        $this->assertSame('params must be an object', $response['error']['message']);
    }

    public function testHandleReturnsMethodNotFoundForUnknownMethod(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'unknown/method',
            'params' => [],
        ]);

        $this->assertSame(-32601, $response['error']['code']);
        $this->assertSame('Method not found', $response['error']['message']);
    }

    public function testToolsCallReturnsInvalidParamsWhenNameIsMissing(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/call',
            'params' => ['arguments' => []],
        ]);

        $this->assertArrayNotHasKey('result', $response);
        $this->assertSame(-32602, $response['error']['code']);
        $this->assertSame('name is required', $response['error']['message']);
    }

    public function testNotificationPingReturnsNoResponse(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'params' => [],
        ]);

        $this->assertSame([], $response);
    }

    public function testInvalidRequestWithoutIdReturnsNullId(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'params' => [],
        ]);

        $this->assertNull($response['id']);
        $this->assertSame(-32600, $response['error']['code']);
    }

    public function testNotificationInitializedReturnsNoResponse(): void
    {
        $notifications = $this->createMock(NotificationsInterface::class);
        $notifications->expects($this->once())
            ->method('handle')
            ->with('notifications/initialized', []);

        $protocolHandler = $this->makeProtocolHandler(['order_get_status'], $notifications);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        $this->assertSame([], $response);
    }

    public function testNotificationWithIdReturnsInvalidRequest(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'notifications/initialized',
        ]);

        $this->assertSame(-32600, $response['error']['code']);
    }

    public function testResourcesListReturnsEmptyList(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'resources/list',
            'params' => [],
        ]);

        $this->assertSame(['resources' => []], $response['result']);
    }

    public function testPromptsListReturnsEmptyList(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 13,
            'method' => 'prompts/list',
            'params' => [],
        ]);

        $this->assertSame(['prompts' => []], $response['result']);
    }

    public function testToolsCallDispatchesToolCallingAndToolCalled(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);
        $dispatcher = $this->testEventDispatcher();
        $dispatcher->clear();

        $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'order_get_status',
                'arguments' => ['orderId' => 42],
            ],
        ]);

        $this->assertCount(1, $dispatcher->getEventsByClass(ToolCalling::class));
        $calling = $dispatcher->getEventsByClass(ToolCalling::class)[0];
        $this->assertSame('order_get_status', $calling->name);
        $this->assertSame(['orderId' => 42], $calling->arguments);

        $this->assertCount(1, $dispatcher->getEventsByClass(ToolCalled::class));
        $called = $dispatcher->getEventsByClass(ToolCalled::class)[0];
        $this->assertSame('{"orderId":42,"status":"paid"}', $called->result['content'][0]['text']);
    }

    public function testToolCalledMutationChangesToolsCallResult(): void
    {
        $this->container->get(ListenerProviderInterface::class)->on(
            null,
            static function (ToolCalled $event): void {
                $event->result['content'][0]['text'] = '{"status":"overridden"}';
            },
        );

        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'order_get_status',
                'arguments' => ['orderId' => 42],
            ],
        ]);

        $this->assertSame('{"status":"overridden"}', $response['result']['content'][0]['text']);
    }

    public function testToolsCallReturnsInvalidParamsWhenArgumentsAreNotObject(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 14,
            'method' => 'tools/call',
            'params' => [
                'name' => 'order_get_status',
                'arguments' => 'bad',
            ],
        ]);

        $this->assertArrayNotHasKey('result', $response);
        $this->assertSame(-32602, $response['error']['code']);
        $this->assertSame('arguments must be an object', $response['error']['message']);
    }

    public function testToolsCallReturnsToolResultIsErrorWhenRequiredArgumentMissing(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 19,
            'method' => 'tools/call',
            'params' => [
                'name' => 'order_get_status',
                'arguments' => [],
            ],
        ]);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertTrue($response['result']['isError']);
        $payload = json_decode($response['result']['content'][0]['text'], true);
        $this->assertSame(\Switon\Mcp\Exception\InvalidParamsException::class, $payload['class']);
        $this->assertStringContainsString('orderId is required', $payload['content']);
    }

    public function testCallOverrideMayPromoteInvalidParamsToJsonRpc(): void
    {
        $this->tools = $this->make(Tools::class, ['tools' => ['order_get_status' => OrderStatusTool::class]]);
        $protocolHandler = $this->make(RethrowingProtocolHandler::class, [
            'serverInfo' => new ServerInfo(),
        ]);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 21,
            'method' => 'tools/call',
            'params' => [
                'name' => 'order_get_status',
                'arguments' => [],
            ],
        ]);

        $this->assertArrayNotHasKey('result', $response);
        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('orderId is required', $response['error']['message']);
    }

    public function testToolsCallReturnsToolResultIsErrorWhenArgumentTypeInvalid(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 20,
            'method' => 'tools/call',
            'params' => [
                'name' => 'order_get_status',
                'arguments' => ['orderId' => 'not-an-int'],
            ],
        ]);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertTrue($response['result']['isError']);
        $payload = json_decode($response['result']['content'][0]['text'], true);
        $this->assertSame(\Switon\Mcp\Exception\InvalidParamsException::class, $payload['class']);
        $this->assertStringContainsString('orderId must be an integer', $payload['content']);
    }

    public function testToolsCallReturnsToolResultIsErrorForUnknownName(): void
    {
        $protocolHandler = $this->makeProtocolHandler(['order_get_status']);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 15,
            'method' => 'tools/call',
            'params' => [
                'name' => 'missing_tool',
                'arguments' => [],
            ],
        ]);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertTrue($response['result']['isError']);
        $payload = json_decode($response['result']['content'][0]['text'], true);
        $this->assertSame(\Switon\Mcp\Exception\ToolNotFoundException::class, $payload['class']);
        $this->assertStringContainsString('missing_tool', $payload['content']);
    }

    public function testToolsCallEncodesScalarReturnValue(): void
    {
        $protocolHandler = $this->makeProtocolHandlerWithTool('scalar_ping', new CallbackTool(
            new ToolSchema(description: 'Scalar'),
            static fn (array $arguments): int => 7,
        ));

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 16,
            'method' => 'tools/call',
            'params' => [
                'name' => 'scalar_ping',
                'arguments' => [],
            ],
        ]);

        $this->assertSame('7', $response['result']['content'][0]['text']);
        $this->assertArrayNotHasKey('structuredContent', $response['result']);
    }

    public function testToolsCallEncodesBoolAndNullAsJsonLiterals(): void
    {
        foreach ([[false, 'false'], [true, 'true'], [null, 'null']] as [$value, $text]) {
            $protocolHandler = $this->makeProtocolHandlerWithTool('literal', new CallbackTool(
                new ToolSchema(description: 'Literal'),
                static fn (array $arguments) => $value,
            ));

            $response = $this->dispatch($protocolHandler, [
                'jsonrpc' => '2.0',
                'id' => 16,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'literal',
                    'arguments' => [],
                ],
            ]);

            $this->assertSame($text, $response['result']['content'][0]['text']);
        }
    }

    public function testToolsCallReturnsToolResultIsErrorWhenToolThrows(): void
    {
        $protocolHandler = $this->makeProtocolHandlerWithTool('boom', new CallbackTool(
            new ToolSchema(description: 'Throws'),
            static function (array $arguments): void {
                throw new RuntimeException('tool failed');
            },
        ));

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 17,
            'method' => 'tools/call',
            'params' => [
                'name' => 'boom',
                'arguments' => [],
            ],
        ]);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertTrue($response['result']['isError']);
        $payload = json_decode($response['result']['content'][0]['text'], true);
        $this->assertSame(RuntimeException::class, $payload['class']);
        $this->assertSame('tool failed', $payload['content']);
        $this->assertArrayNotHasKey('structuredContent', $response['result']);
    }

    public function testToolsCallReturnsToolResultIsErrorWhenToolReturnsToolResult(): void
    {
        $protocolHandler = $this->makeProtocolHandlerWithTool('denied', new CallbackTool(
            new ToolSchema(description: 'Denied'),
            static fn (array $arguments): ToolResult => new ToolResult(
                ['error' => 'denied', 'reason' => 'nope'],
                true,
            ),
        ));

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 18,
            'method' => 'tools/call',
            'params' => [
                'name' => 'denied',
                'arguments' => [],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $payload = json_decode($response['result']['content'][0]['text'], true);
        $this->assertSame(['error' => 'denied', 'reason' => 'nope'], $payload);
    }

    public function testHandleReturnsServerErrorWhenNotificationThrows(): void
    {
        $notifications = new class () implements NotificationsInterface {
            public function handle(string $method, array $params): void
            {
                throw new RuntimeException('notify failed');
            }
        };
        $protocolHandler = $this->makeProtocolHandler([], $notifications);

        $response = $protocolHandler->handle([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => [],
        ], $this->tools);

        $this->assertSame(-32000, $response['error']['code']);
        $this->assertSame('notify failed', $response['error']['message']);
    }

    public function testToolsListSkipsToolWhoseSchemaIsNull(): void
    {
        $protocolHandler = $this->makeProtocolHandler([]);
        $tools = new class () implements ToolsInterface {
            public function all(): array
            {
                return ['gone' => new DisabledTool()];
            }

            public function get(string $name): ?ToolInterface
            {
                return null;
            }

            public function has(string $name): bool
            {
                return true;
            }

            public function add(string $name, ToolInterface $tool): void
            {
            }
        };

        $response = $protocolHandler->handle([
            'jsonrpc' => '2.0',
            'id' => 30,
            'method' => 'tools/list',
            'params' => [],
        ], $tools);

        $this->assertSame([], $response['result']['tools']);
    }

    public function testToolsCallReturnsToolResultWhenSchemaDisappears(): void
    {
        $tool = new class () implements ToolInterface {
            private int $seen = 0;

            public function schema(string $name): ?ToolSchema
            {
                return ++$this->seen === 1 ? new ToolSchema(description: 'Once') : null;
            }

            public function call(string $name, array $arguments): mixed
            {
                return 'skip';
            }
        };
        $protocolHandler = $this->makeProtocolHandlerWithTool('flaky', $tool);

        $response = $this->dispatch($protocolHandler, [
            'jsonrpc' => '2.0',
            'id' => 31,
            'method' => 'tools/call',
            'params' => [
                'name' => 'flaky',
                'arguments' => [],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $payload = json_decode($response['result']['content'][0]['text'], true);
        $this->assertSame(\Switon\Mcp\Exception\ToolNotFoundException::class, $payload['class']);
    }

    protected function testEventDispatcher(): TestEventDispatcher
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);

        return $dispatcher;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    protected function dispatch(ProtocolHandler $protocolHandler, array $request): array
    {
        return $protocolHandler->handle($request, $this->tools);
    }

    /**
     * @param list<string> $toolNames
     */
    protected function makeProtocolHandler(array $toolNames, ?NotificationsInterface $notifications = null): ProtocolHandler
    {
        $map = [];
        foreach ($toolNames as $toolName) {
            $map[$toolName] = OrderStatusTool::class;
        }

        return $this->makeProtocolHandlerWithDiscovered($map, $notifications);
    }

    /**
     * @param array<string, class-string> $tools
     */
    protected function makeProtocolHandlerWithDiscovered(array $tools, ?NotificationsInterface $notifications = null): ProtocolHandler
    {
        $this->tools = $this->make(Tools::class, ['tools' => $tools]);
        $parameters = [
            'serverInfo' => new ServerInfo(),
        ];
        if ($notifications !== null) {
            $parameters['notifications'] = $notifications;
        }

        return $this->make(ProtocolHandler::class, $parameters);
    }

    protected function makeProtocolHandlerWithTool(string $name, ToolInterface $tool): ProtocolHandler
    {
        $this->tools = $this->make(Tools::class);
        $this->tools->add($name, $tool);

        return $this->make(ProtocolHandler::class, [
            'serverInfo' => new ServerInfo(),
        ]);
    }
}
