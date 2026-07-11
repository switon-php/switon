<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\StopFlow;
use Switon\Http\Event\RequestInvoked;
use Switon\Http\Event\RequestReceived;
use Switon\Http\ExceptionDispatcherInterface;
use Switon\Http\RequestContext;
use Switon\Http\RequestHandlerInterface;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Http\ServerInterface;
use Switon\Http\Tests\TestCase;
use Switon\Http\Ws\ConnContext;
use Switon\Http\Ws\Handler;
use Switon\Http\Ws\Message;
use Switon\Http\WsSenderInterface;
use Switon\Invoking\InvokerInterface;
use Switon\Routing\Exception\NotFoundRouteException;
use Switon\Routing\MatcherInterface;
use Switon\Routing\RouterInterface;
use Swoole\Http\Request as SwooleRequest;
use Swoole\WebSocket\Frame;
use Throwable;
use RuntimeException;

#[RequiresPhpExtension('swoole')]
class WsHandlerTest extends TestCase
{
    private ConnContext $connections;
    /** @var list<object> */
    private array $events = [];
    /** @var list<array{0: string, 1: array<int|string, mixed>}> */
    private array $invocations = [];
    private mixed $invokeResult = null;
    private ?Throwable $invokeFailure = null;
    private RecordingWsServer $sender;
    private RequestContext $requestContext;

    public function testOpenBindsConnectionAndPushesStringContent(): void
    {
        $handler = $this->handler();
        $this->responseContent = 'hello';

        $handler->onOpen($this->swooleRequest(4, ['content-type' => 'application/json', 'host' => 'example.test'], ['q' => '1'], ['sid' => 'a']));

        $this->assertSame([
            'controller' => WsChatController::class,
            'variables' => ['room' => '1'],
            'path' => '/chat',
        ], $this->connections->get(4));
        $this->assertSame([[4, 'hello']], $this->sender->pushes);
        $received = $this->eventsOf(RequestReceived::class);
        $this->assertCount(1, $received);
        $this->assertSame('application/json', $received[0]->SERVER['CONTENT_TYPE']);
        $this->assertSame('example.test', $received[0]->SERVER['HTTP_HOST']);
        $this->assertSame(['q' => '1'], $received[0]->GET);
        $this->assertSame(['sid' => 'a'], $received[0]->COOKIE);
        $this->assertSame('open', $this->invocations[0][0]);
        $this->assertSame(4, $this->invocations[0][1]['fd']);
    }

    public function testOpenMissDoesNotBind(): void
    {
        $exceptions = [];
        $handler = $this->handler(exceptions: $exceptions, missVerbs: ['WS_OPEN']);

        $handler->onOpen($this->swooleRequest(4));

        $this->assertNull($this->connections->get(4));
        $this->assertSame([], $exceptions);
        $this->assertSame([], $this->sender->pushes);
    }

    public function testOpenStopFlowDoesNotDispatchTheException(): void
    {
        $this->invokeFailure = StopFlow::abort();
        $exceptions = [];
        $handler = $this->handler(exceptions: $exceptions);

        $handler->onOpen($this->swooleRequest(4));

        $this->assertSame([], $exceptions);
        $this->assertNotNull($this->connections->get(4));
    }

    public function testOpenFailureGoesToExceptionDispatcher(): void
    {
        $this->invokeFailure = new RuntimeException('boom');
        $exceptions = [];
        $handler = $this->handler(exceptions: $exceptions);

        $handler->onOpen($this->swooleRequest(4));

        $this->assertCount(1, $exceptions);
        $this->assertSame('boom', $exceptions[0]->getMessage());
    }

    public function testMessageWithoutConnectionDoesNothing(): void
    {
        $handler = $this->handler();

        $handler->onMessage($this->frame(9, '{"a":1}'));

        $this->assertSame([], $this->invocations);
    }

    public function testMessageMergesJsonObjectAndRestoresPreviousRequest(): void
    {
        $handler = $this->handler();
        $this->connections->bind(4, WsChatController::class, ['room' => '1'], '/chat');
        $this->requestContext->_REQUEST = ['old' => 'kept'];
        $this->requestContext->_POST = ['dirty' => true];
        $this->requestContext->rawBody = 'raw';
        $seen = null;
        $this->onInvoke = function (array $parameters) use (&$seen): void {
            $seen = $this->requestContext->_REQUEST;
        };

        $handler->onMessage($this->frame(4, '  {"room":"json","extra":2}'));

        $this->assertSame('1', $seen['room']);
        $this->assertSame(2, $seen['extra']);
        $this->assertSame(4, $seen['fd']);
        $this->assertInstanceOf(Message::class, $this->invocations[0][1]['message']);
        $this->assertSame(['old' => 'kept'], $this->requestContext->_REQUEST);
        $this->assertSame([], $this->requestContext->_POST);
        $this->assertNull($this->requestContext->rawBody);
    }

    public function testMessageLeavesNonObjectTextUnparsed(): void
    {
        $handler = $this->handler();
        $this->connections->bind(4, WsChatController::class, [], '/chat');

        $handler->onMessage($this->frame(4, '[1,2]'));

        $this->assertArrayNotHasKey(0, $this->invocations[0][1]);
        $this->assertSame('[1,2]', $this->invocations[0][1]['message']->data);
    }

    public function testMessageInvalidJsonGoesToExceptionDispatcher(): void
    {
        $exceptions = [];
        $handler = $this->handler(exceptions: $exceptions);
        $this->connections->bind(4, WsChatController::class, [], '/chat');

        $handler->onMessage($this->frame(4, '{'));

        $this->assertCount(1, $exceptions);
        $this->assertSame([], $this->invocations);
    }

    public function testMessageMissStillRestoresRequestContext(): void
    {
        $handler = $this->handler(missVerbs: ['WS_MESSAGE']);
        $this->connections->bind(4, WsChatController::class, [], '/chat');
        $this->requestContext->_REQUEST = ['old' => 'kept'];

        $handler->onMessage($this->frame(4, 'ping'));

        $this->assertSame(['old' => 'kept'], $this->requestContext->_REQUEST);
        $this->assertNotNull($this->connections->get(4));
    }

    public function testMessageReturnIsStringifiedAndPushed(): void
    {
        $this->invokeResult = ['ok' => 1];
        $this->responseContent = ['ok' => 1];
        $handler = $this->handler();
        $this->connections->bind(4, WsChatController::class, [], '/chat');

        $handler->onMessage($this->frame(4, 'ping'));

        $this->assertNotEmpty($this->eventsOf(RequestInvoked::class));
        $this->assertSame([[4, '{"ok":1}']], $this->sender->pushes);
    }

    public function testCloseWithoutConnectionDoesNothing(): void
    {
        $handler = $this->handler();

        $handler->onClose(9);

        $this->assertSame([], $this->invocations);
    }

    public function testErrorMissDoesNotDispatch(): void
    {
        $exceptions = [];
        $handler = $this->handler(exceptions: $exceptions, missVerbs: ['WS_ERROR']);
        $this->connections->bind(4, WsChatController::class, [], '/chat');

        $handler->onError(4, 1);

        $this->assertSame([], $exceptions);
        $this->assertSame([], $this->invocations);
    }

    public function testErrorStopFlowIsSwallowed(): void
    {
        $this->invokeFailure = StopFlow::abort();
        $exceptions = [];
        $handler = $this->handler(exceptions: $exceptions);
        $this->connections->bind(4, WsChatController::class, [], '/chat');

        $handler->onError(4, 1);

        $this->assertSame([], $exceptions);
    }

    public function testErrorFailureGoesToExceptionDispatcher(): void
    {
        $this->invokeFailure = new RuntimeException('broken');
        $exceptions = [];
        $handler = $this->handler(exceptions: $exceptions);
        $this->connections->bind(4, WsChatController::class, [], '/chat');

        $handler->onError(4, 1);

        $this->assertSame('broken', $exceptions[0]->getMessage());
    }

    public function testCloseStopFlowStillRemovesTheBinding(): void
    {
        $this->invokeFailure = StopFlow::abort();
        $exceptions = [];
        $handler = $this->handler(exceptions: $exceptions);
        $this->connections->bind(4, WsChatController::class, [], '/chat');

        $handler->onClose(4);

        $this->assertSame([], $exceptions);
        $this->assertNull($this->connections->get(4));
    }

    public function testCloseFailureStillRemovesTheBinding(): void
    {
        $this->invokeFailure = new RuntimeException('closed');
        $exceptions = [];
        $handler = $this->handler(exceptions: $exceptions);
        $this->connections->bind(4, WsChatController::class, [], '/chat');

        $handler->onClose(4);

        $this->assertSame('closed', $exceptions[0]->getMessage());
        $this->assertNull($this->connections->get(4));
    }

    public function testCloseRemovesBindingEvenWhenRouteIsMissing(): void
    {
        $handler = $this->handler(missVerbs: ['WS_CLOSE']);
        $this->connections->bind(4, WsChatController::class, [], '/chat');

        $handler->onClose(4);

        $this->assertNull($this->connections->get(4));
    }

    public function testCloseInvokesTheCloseAction(): void
    {
        $handler = $this->handler();
        $this->connections->bind(4, WsChatController::class, ['room' => '1'], '/chat');

        $handler->onClose(4);

        $this->assertSame('close', $this->invocations[0][0]);
        $this->assertSame(4, $this->invocations[0][1]['fd']);
        $this->assertNull($this->connections->get(4));
    }

    public function testErrorPassesReactorId(): void
    {
        $handler = $this->handler();
        $this->connections->bind(4, WsChatController::class, [], '/chat');

        $handler->onError(4, 2);

        $this->assertSame('error', $this->invocations[0][0]);
        $this->assertSame(2, $this->invocations[0][1]['reactorId']);
    }

    public function testErrorWithoutConnectionDoesNothing(): void
    {
        $handler = $this->handler();

        $handler->onError(9, 1);

        $this->assertSame([], $this->invocations);
    }

    public function testFlushSkipsWhenServerCannotPush(): void
    {
        $this->responseContent = 'hello';
        $handler = $this->handler(sender: false);

        $handler->onOpen($this->swooleRequest(4));

        $this->assertSame([], $this->sender->pushes);
    }

    public function testFlushSkipsEmptyOrUnestablishedSockets(): void
    {
        $this->responseContent = '';
        $handler = $this->handler();
        $handler->onOpen($this->swooleRequest(4));
        $this->assertSame([], $this->sender->pushes);

        $this->responseContent = 'hello';
        $this->sender->established = false;
        $this->connections->bind(5, WsChatController::class, [], '/chat');
        $handler->onMessage($this->frame(5, 'ping'));
        $this->assertSame([], $this->sender->pushes);
    }

    /**
     * @param list<Throwable> $exceptions
     * @param list<string> $missVerbs
     */
    private function handler(array &$exceptions = [], array $missVerbs = [], bool $sender = true): Handler
    {
        $this->connections = new ConnContext();
        $this->events = [];
        $this->invocations = [];
        $this->requestContext = new RequestContext();
        $this->sender = new RecordingWsServer();

        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            $this->events[] = $event;
            return $event;
        });

        $request = $this->createStub(RequestInterface::class);
        $request->method('path')->willReturn('/chat');
        $request->method('getContext')->willReturn($this->requestContext);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('hasContent')->willReturnCallback(fn (): bool => $this->responseContent !== null && $this->responseContent !== '');
        $response->method('getContent')->willReturnCallback(fn (): mixed => $this->responseContent);
        $response->method('setContent')->willReturnCallback(function (mixed $content) use ($response): ResponseInterface {
            $this->responseContent = $content;
            return $response;
        });

        $router = $this->createStub(RouterInterface::class);
        $router->method('match')->willReturnCallback(function (string $path, string $verb) use ($missVerbs): MatcherInterface {
            if (in_array($verb, $missVerbs, true)) {
                throw NotFoundRouteException::of($path);
            }
            $action = match ($verb) {
                'WS_OPEN' => 'open',
                'WS_MESSAGE' => 'message',
                'WS_CLOSE' => 'close',
                'WS_ERROR' => 'error',
                default => 'open',
            };
            $matcher = $this->createStub(MatcherInterface::class);
            $matcher->method('getHandler')->willReturn(WsChatController::class . '::' . $action);
            $matcher->method('getVariables')->willReturn(['room' => '1']);
            return $matcher;
        });

        $invoker = $this->createStub(InvokerInterface::class);
        $invoker->method('invoke')->willReturnCallback(function (callable $callable, array $parameters): mixed {
            $method = is_array($callable) ? (string)$callable[1] : 'invoke';
            $this->invocations[] = [$method, $parameters];
            if ($this->onInvoke !== null) {
                ($this->onInvoke)($parameters);
            }
            if ($this->invokeFailure !== null) {
                throw $this->invokeFailure;
            }
            return $this->invokeResult;
        });

        $exceptionsDispatcher = $this->createStub(ExceptionDispatcherInterface::class);
        $exceptionsDispatcher->method('dispatch')->willReturnCallback(function (Throwable $throwable) use (&$exceptions): void {
            $exceptions[] = $throwable;
        });

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) use ($dispatcher): mixed {
            if ($id === EventDispatcherInterface::class) {
                return $dispatcher;
            }
            if ($id === WsChatController::class) {
                return new WsChatController();
            }
            throw new RuntimeException('unexpected service ' . $id);
        });

        $server = $sender ? $this->sender : $this->createStub(ServerInterface::class);

        return $this->make(Handler::class, [
            'container' => $container,
            'eventDispatcher' => $dispatcher,
            'requestHandler' => $this->createStub(RequestHandlerInterface::class),
            'router' => $router,
            'request' => $request,
            'response' => $response,
            'invoker' => $invoker,
            'exceptionDispatcher' => $exceptionsDispatcher,
            'connContext' => $this->connections,
            'server' => $server,
        ]);
    }

    private mixed $responseContent = null;
    /** @var null|callable(array<int|string, mixed>): void */
    private $onInvoke = null;

    /**
     * @param class-string $class
     *
     * @return list<object>
     */
    private function eventsOf(string $class): array
    {
        return array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof $class));
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, string> $cookies
     */
    private function swooleRequest(int $fd, array $headers = [], array $query = [], array $cookies = []): SwooleRequest
    {
        $request = new SwooleRequest();
        $request->fd = $fd;
        $request->server = ['request_uri' => '/chat'];
        $request->header = $headers;
        $request->get = $query;
        $request->cookie = $cookies;

        return $request;
    }

    private function frame(int $fd, string $data): Frame
    {
        $frame = new Frame();
        $frame->fd = $fd;
        $frame->data = $data;

        return $frame;
    }
}

class WsChatController
{
    public function open(): void
    {
    }

    public function message(): void
    {
    }

    public function close(): void
    {
    }

    public function error(): void
    {
    }
}

class RecordingWsServer implements ServerInterface, WsSenderInterface
{
    /** @var list<array{0: int, 1: string}> */
    public array $pushes = [];
    public bool $established = true;

    public function push(int $fd, string $data): bool
    {
        $this->pushes[] = [$fd, $data];

        return true;
    }

    public function disconnect(int $fd): bool
    {
        return true;
    }

    public function isEstablished(int $fd): bool
    {
        return $this->established;
    }

    public function start(): void
    {
    }

    public function write(string $chunk): bool
    {
        return true;
    }

    public function sendHeaders(): void
    {
    }

    public function sendBody(): void
    {
    }
}
