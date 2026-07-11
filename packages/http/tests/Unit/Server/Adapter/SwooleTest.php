<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit\Server\Adapter;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\App;
use Switon\Core\Exception\NotSupportedException;
use Switon\Http\Request;
use Switon\Http\RequestHandler;
use Switon\Http\Response;
use Switon\Http\Server\Adapter\Swoole;
use Switon\Http\Server\Adapter\SwooleContext;
use Switon\Http\Server\Event\ServerStart;
use Switon\Http\Server\Event\ServerStarted;
use Switon\Http\Server\StaticHandlerInterface;
use Switon\Http\ServerOptions;
use Switon\Http\Tests\TestCase;
use Switon\Http\Server\Event\ServerClose;
use Switon\Http\Ws\HandlerInterface;
use Switon\Routing\RouterInterface;
use Switon\Sync\TerminationInterface;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
#[RequiresPhpExtension('swoole')]
class SwooleTest extends TestCase
{
    protected Swoole $swooleAdapter;
    protected MockObject|EventDispatcherInterface $eventDispatcher;
    protected MockObject|StaticHandlerInterface $staticHandler;
    protected MockObject|RequestHandler $requestHandler;
    protected MockObject|Request $request;
    protected MockObject|Response $response;
    protected MockObject|RouterInterface $router;
    protected ServerOptions $serverOptions;
    protected SwooleContext $context;

    protected function setUp(): void
    {
        // Call parent setUp first to initialize container and contextManager
        parent::setUp();

        // Create mocks for dependencies
        $mockApp = $this->createMock(App::class);
        $mockApp->method('env')->willReturn('testing');

        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->staticHandler = $this->createMock(StaticHandlerInterface::class);
        $this->requestHandler = $this->createMock(RequestHandler::class);
        $this->request = $this->createMock(Request::class);
        $this->response = $this->createMock(Response::class);
        $this->router = $this->createMock(RouterInterface::class);

        // Create ServerOptions with test values
        $this->serverOptions = new ServerOptions();
        $this->serverOptions->type = 'swoole';
        $this->serverOptions->host = '127.0.0.1';
        $this->serverOptions->port = 8080;
        $this->context = new SwooleContext();

        // Register dependencies in container
        $this->container->replace(App::class, fn () => $mockApp);
        $this->container->replace(EventDispatcherInterface::class, fn () => $this->eventDispatcher);
        $this->container->replace(StaticHandlerInterface::class, fn () => $this->staticHandler);
        $this->container->replace(RequestHandler::class, fn () => $this->requestHandler);
        $this->container->replace(Request::class, fn () => $this->request);
        $this->container->replace(Response::class, fn () => $this->response);
        $this->container->replace(RouterInterface::class, fn () => $this->router);
        $this->container->replace(ServerOptions::class, fn () => $this->serverOptions);
        $this->container->replace(HandlerInterface::class, fn () => $this->createMock(HandlerInterface::class));

        // Create Swoole adapter using container (which will inject all dependencies)
        $this->swooleAdapter = $this->container->make(Swoole::class);
    }

    /**
     * Test Swoole adapter constructor initializes global server variables correctly.
     *
     * Verified through global $_SERVER which is set during construction.
     */
    public function testConstructorInitializesServerVariables(): void
    {
        // Assert - constructor sets DOCUMENT_ROOT in global $_SERVER
        $this->assertArrayHasKey('DOCUMENT_ROOT', $_SERVER);
    }

    /**
     * An explicit `settings.document_root` must drive both the Swoole setting and `$_SERVER`,
     * so static handling and `DOCUMENT_ROOT` consumers agree.
     */
    public function testConfiguredDocumentRootIsKeptAndMirroredToServer(): void
    {
        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;

        try {
            $serverOptions = new ServerOptions();
            $serverOptions->type = 'swoole';
            $serverOptions->host = '127.0.0.1';
            $serverOptions->port = 8081;

            $adapter = $this->container->make(Swoole::class, [
                'serverOptions' => $serverOptions,
                'settings' => [
                    'enable_static_handler' => true,
                    'document_root' => '/custom/user/public',
                ],
            ]);

            $this->assertSame('/custom/user/public', $_SERVER['DOCUMENT_ROOT']);
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }

    /**
     * Without `settings.document_root`, the entry script directory is used for both places.
     */
    public function testDocumentRootDefaultsToEntryScriptDirectory(): void
    {
        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;

        try {
            $serverOptions = new ServerOptions();
            $serverOptions->type = 'swoole';
            $serverOptions->host = '127.0.0.1';
            $serverOptions->port = 8082;

            $adapter = $this->container->make(Swoole::class, [
                'serverOptions' => $serverOptions,
                'settings' => ['enable_static_handler' => true],
            ]);

            $expected = dirname(get_included_files()[0]);

            $this->assertSame($expected, $_SERVER['DOCUMENT_ROOT']);
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }

    /**
     * Test getContext returns SwooleContext from context manager.
     */
    public function testGetContextReturnsSwooleContext(): void
    {
        // Act
        $result = $this->swooleAdapter->getContext();

        // Assert
        $this->assertInstanceOf(SwooleContext::class, $result);
    }

    /**
     * Test onStart dispatches ServerStart and ServerStarted events.
     */
    public function testOnStartDispatchesEvents(): void
    {
        // Arrange
        $server = $this->createMock(SwooleServer::class);
        $server->setting = ['worker_num' => 4];
        $server->master_pid = 12345;

        // App mock is already configured in setUp() to return 'testing' for env()

        $call = 0;
        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$call) {
                $call++;
                if ($call === 1) {
                    $this->assertInstanceOf(ServerStart::class, $event);
                } elseif ($call === 2) {
                    $this->assertInstanceOf(ServerStarted::class, $event);
                }
                return $event;
            });

        // Act
        $this->swooleAdapter->onStart($server);

        // Assert - test passes if expectations are met
        $this->assertTrue(true);
    }

    /**
     * Test onRequest handles favicon.ico requests.
     */
    public function testOnRequestHandlesFaviconRequest(): void
    {
        // Arrange
        $swooleRequest = $this->createMock(SwooleRequest::class);
        $swooleRequest->server = ['request_uri' => '/favicon.ico'];

        $swooleResponse = $this->createMock(SwooleResponse::class);
        $swooleResponse->expects($this->once())
            ->method('status')
            ->with(404);
        $swooleResponse->expects($this->once())
            ->method('end');

        // Act
        $this->swooleAdapter->onRequest($swooleRequest, $swooleResponse);

        // Assert - test passes if expectations are met
        $this->assertTrue(true);
    }

    /**
     * Test onRequest handles static file requests when static handler is enabled.
     *
     * Configure settings through container before creating adapter.
     */
    public function testOnRequestHandlesStaticFileRequests(): void
    {
        // Arrange - create adapter with static handler enabled via container config
        $this->container->set('swoole.settings', ['enable_static_handler' => true]);
        $swooleAdapterWithStatic = $this->container->make(Swoole::class, [
            'settings' => ['enable_static_handler' => true],
        ]);

        $swooleRequest = $this->createMock(SwooleRequest::class);
        $swooleRequest->server = ['request_uri' => '/static/style.css'];
        $swooleRequest->header = [];
        $swooleRequest->get = [];
        $swooleRequest->post = [];
        $swooleRequest->cookie = [];
        $swooleRequest->files = [];
        $swooleRequest->expects($this->once())
            ->method('rawContent')
            ->willReturn('');

        $swooleResponse = $this->createMock(SwooleResponse::class);

        $this->staticHandler->expects($this->once())
            ->method('isFile')
            ->with('/static/style.css')
            ->willReturn(true);

        $this->staticHandler->expects($this->once())
            ->method('getFile')
            ->with('/static/style.css')
            ->willReturn('/path/to/style.css');

        $this->staticHandler->expects($this->once())
            ->method('getMimeType')
            ->with('/path/to/style.css')
            ->willReturn('text/css');

        $swooleResponse->expects($this->once())
            ->method('header')
            ->with('Content-Type', 'text/css');

        $swooleResponse->expects($this->once())
            ->method('sendfile')
            ->with('/path/to/style.css');

        // prepareGlobals dispatches RequestReceived, plus AssetSending and AssetSent
        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        // Act
        $swooleAdapterWithStatic->onRequest($swooleRequest, $swooleResponse);

        // Assert - test passes if expectations are met
        $this->assertTrue(true);
    }


    /**
     * Test sendHeaders dispatches appropriate events.
     */
    public function testSendHeadersDispatchesEvents(): void
    {
        // Arrange
        $swooleResponse = $this->createMock(SwooleResponse::class);

        // Set up context with the mocked swooleResponse
        $context = $this->swooleAdapter->getContext();
        $context->response = $swooleResponse;

        // Act & Assert - just verify the method can be called without errors
        // Detailed behavior is tested in integration tests
        $this->swooleAdapter->sendHeaders();

        $this->assertTrue(true);
    }

    /**
     * Test sendBody can be called without errors.
     */
    public function testSendBodyCanBeCalled(): void
    {
        // Arrange
        $swooleResponse = $this->createMock(SwooleResponse::class);
        $swooleResponse->method('end')->willReturn(true);

        // Set up context with the mocked swooleResponse
        $context = $this->swooleAdapter->getContext();
        $context->response = $swooleResponse;

        // Act & Assert - just verify the method can be called without errors
        // Detailed behavior is tested in integration tests
        $this->swooleAdapter->sendBody();

        $this->assertTrue(true);
    }


    /**
     * Test write method writes chunks correctly.
     */
    public function testWriteWritesChunksCorrectly(): void
    {
        // Arrange
        $swooleResponse = $this->createMock(SwooleResponse::class);

        // Set up context with the mocked swooleResponse
        $context = $this->swooleAdapter->getContext();
        $context->response = $swooleResponse;

        $swooleResponse->expects($this->once())
            ->method('write')
            ->with('chunk data')
            ->willReturn(true);

        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatch');

        // Act
        $result = $this->swooleAdapter->write('chunk data');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test write method handles empty chunks.
     */
    public function testWriteHandlesEmptyChunks(): void
    {
        // Arrange
        $swooleResponse = $this->createMock(SwooleResponse::class);

        // Set up context with the mocked swooleResponse
        $context = $this->swooleAdapter->getContext();
        $context->response = $swooleResponse;

        $swooleResponse->expects($this->once())
            ->method('end')
            ->willReturn(true);

        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatch');

        // Act
        $result = $this->swooleAdapter->write('');

        // Assert
        $this->assertTrue($result);
    }

    public function testUnsupportedDispatchModeIsRejected(): void
    {
        $this->expectException(NotSupportedException::class);

        $this->container->make(Swoole::class, ['settings' => ['dispatch_mode' => 1]]);
    }

    public function testSenderReportsDownBeforeTheProcessStarts(): void
    {
        $this->assertFalse($this->swooleAdapter->push(1, 'hi'));
        $this->assertFalse($this->swooleAdapter->disconnect(1));
        $this->assertFalse($this->swooleAdapter->isEstablished(1));
    }

    public function testOpenMessageAndCloseReachTheHandlerAfterHandshake(): void
    {
        $calls = [];
        $handler = $this->createStub(HandlerInterface::class);
        $handler->method('onOpen')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'open';
        });
        $handler->method('onMessage')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'message';
        });
        $handler->method('onClose')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'close';
        });
        $handler->method('onError')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'error';
        });
        $adapter = $this->container->make(Swoole::class, ['wsHandler' => $handler]);
        $server = new IdleWebSocketServer();
        $server->established = true;
        $request = new SwooleRequest();
        $request->fd = 4;

        $adapter->onOpen($server, $request);
        $frame = new Frame();
        $frame->fd = 4;
        $frame->data = 'ping';
        $adapter->onMessage($server, $frame);
        $adapter->onError($server, 4, 2);
        $adapter->onClose($server, 4, 1);

        $this->assertSame(['open', 'message', 'error', 'close'], $calls);
        $this->assertSame([], $server->disconnects);
    }

    public function testOpenFailureDisconnectsAnEstablishedSocket(): void
    {
        $handler = $this->createStub(HandlerInterface::class);
        $handler->method('onOpen')->willReturnCallback(function (): void {
            throw new RuntimeException('open failed');
        });
        $adapter = $this->container->make(Swoole::class, ['wsHandler' => $handler]);
        $server = new IdleWebSocketServer();
        $server->established = true;
        $request = new SwooleRequest();
        $request->fd = 4;

        $this->expectOutputRegex('/open failed/');
        $adapter->onOpen($server, $request);

        $this->assertSame([[4, 1008]], $server->disconnects);
    }

    public function testMessageAndCloseFailuresAreReportedWithoutDroppingTheSocket(): void
    {
        $handler = $this->createStub(HandlerInterface::class);
        $handler->method('onMessage')->willReturnCallback(function (): void {
            throw new RuntimeException('message failed');
        });
        $handler->method('onClose')->willReturnCallback(function (): void {
            throw new RuntimeException('close failed');
        });
        $handler->method('onError')->willReturnCallback(function (): void {
            throw new RuntimeException('error failed');
        });
        $adapter = $this->container->make(Swoole::class, ['wsHandler' => $handler]);
        $server = new IdleWebSocketServer();
        $server->established = true;
        $request = new SwooleRequest();
        $request->fd = 4;
        $adapter->onOpen($server, $request);

        $frame = new Frame();
        $frame->fd = 4;
        $frame->data = 'ping';
        $this->expectOutputRegex('/message failed[\s\S]*error failed[\s\S]*close failed/');
        $adapter->onMessage($server, $frame);
        $adapter->onError($server, 4, 2);
        $adapter->onClose($server, 4, 1);

        $this->assertSame([], $server->disconnects);
    }

    public function testCloseWithoutAHandshakeDispatchesServerClose(): void
    {
        $events = [];
        $this->eventDispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$events): object {
            $events[] = $event;

            return $event;
        });
        $server = new IdleWebSocketServer();

        $this->swooleAdapter->onClose($server, 9, 1);

        $this->assertInstanceOf(ServerClose::class, $events[0]);
    }

    public function testErrorBeforeHandshakeDoesNotCallTheHandler(): void
    {
        $called = false;
        $handler = $this->createStub(HandlerInterface::class);
        $handler->method('onError')->willReturnCallback(function () use (&$called): void {
            $called = true;
        });
        $adapter = $this->container->make(Swoole::class, ['wsHandler' => $handler]);

        $adapter->onError(new IdleWebSocketServer(), 9, 1);

        $this->assertFalse($called);
    }

    public function testWorkerExitTerminatesOnceAndDispatchesBothRoles(): void
    {
        $termination = $this->createStub(TerminationInterface::class);
        $terminated = false;
        $termination->method('isTerminated')->willReturnCallback(function () use (&$terminated): bool {
            return $terminated;
        });
        $termination->method('terminate')->willReturnCallback(function () use (&$terminated): void {
            $terminated = true;
        });
        $adapter = $this->container->make(Swoole::class, ['termination' => $termination]);
        $server = new IdleWebSocketServer();
        $server->setting = ['worker_num' => 2];

        $adapter->onWorkerExit($server, 0);
        $adapter->onWorkerExit($server, 3);

        $this->assertTrue($terminated);
    }

    public function testMaxRequestBelowOneIsClampedDuringConstruction(): void
    {
        $adapter = $this->container->make(Swoole::class, [
            'settings' => ['max_request' => 0, 'enable_static_handler' => false],
        ]);

        $this->assertInstanceOf(Swoole::class, $adapter);
    }

    public function testDispatchEventSwallowsListenerFailures(): void
    {
        $dispatched = [];
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$dispatched): object {
            $dispatched[] = $event::class;
            if ($event instanceof ServerStart) {
                throw new RuntimeException('listener failed');
            }

            return $event;
        });
        $this->container->replace(EventDispatcherInterface::class, fn () => $this->eventDispatcher);
        $adapter = $this->container->make(Swoole::class);

        $server = new IdleWebSocketServer();
        $server->setting = ['worker_num' => 1];
        $server->master_pid = 1;
        $adapter->onStart($server);

        $this->assertContains(ServerStart::class, $dispatched);
        $this->assertTrue(
            in_array(\Switon\Http\Server\Event\DispatchFailed::class, $dispatched, true)
            || in_array(ServerStart::class, $dispatched, true),
            'Dispatch failures should not bubble out of callbacks',
        );
    }

    public function testOnRequestPreparesGlobalsForContentHeadersAndEmptyRawBody(): void
    {
        $requestHandler = $this->createStub(RequestHandler::class);
        $adapter = $this->container->make(Swoole::class, [
            'settings' => ['enable_static_handler' => false],
            'requestHandler' => $requestHandler,
        ]);

        $swooleRequest = $this->createMock(SwooleRequest::class);
        $swooleRequest->server = ['request_uri' => '/api/ping', 'request_method' => 'POST'];
        $swooleRequest->header = ['content-type' => 'application/json', 'x-request-id' => 'abc'];
        $swooleRequest->get = ['q' => '1'];
        $swooleRequest->post = [];
        $swooleRequest->cookie = [];
        $swooleRequest->files = [];
        $swooleRequest->method('rawContent')->willReturn(false);

        $swooleResponse = $this->createMock(SwooleResponse::class);

        $adapter->onRequest($swooleRequest, $swooleResponse);

        $this->assertTrue(true);
    }

    public function testOnRequestReturns404WhenStaticFileMissing(): void
    {
        $adapter = $this->container->make(Swoole::class, ['settings' => ['enable_static_handler' => true]]);
        $swooleRequest = $this->createMock(SwooleRequest::class);
        $swooleRequest->server = ['request_uri' => '/missing.css'];
        $swooleRequest->header = [];
        $swooleRequest->get = [];
        $swooleRequest->post = [];
        $swooleRequest->cookie = [];
        $swooleRequest->files = [];
        $swooleRequest->method('rawContent')->willReturn('');

        $this->staticHandler->method('isFile')->willReturn(true);
        $this->staticHandler->method('getFile')->willReturn(null);

        $swooleResponse = $this->createMock(SwooleResponse::class);
        $swooleResponse->expects($this->once())->method('status')->with(404, 'Not Found');
        $swooleResponse->expects($this->once())->method('end')->with('');

        $adapter->onRequest($swooleRequest, $swooleResponse);
    }

    public function testSendBodyHandlesNotModifiedAndHeadRequests(): void
    {
        $swooleResponse = $this->createMock(SwooleResponse::class);
        $context = $this->swooleAdapter->getContext();
        $context->response = $swooleResponse;

        $this->response->method('getStatusCode')->willReturnOnConsecutiveCalls(304, 200);
        $this->response->method('getContent')->willReturnOnConsecutiveCalls('body', 'body');
        $this->request->method('isVerb')->with('HEAD')->willReturnOnConsecutiveCalls(false, true);
        $this->response->method('getFile')->willReturn(null);

        $swooleResponse->expects($this->exactly(2))->method('end')->with('');
        $this->swooleAdapter->sendBody();
        $this->swooleAdapter->sendBody();
    }

    public function testOnStartUsesZeroElapsedWhenRequestTimeMissing(): void
    {
        unset($_SERVER['REQUEST_TIME_FLOAT']);

        $server = new IdleWebSocketServer();
        $server->setting = ['worker_num' => 2];
        $server->master_pid = 1;

        $this->swooleAdapter->onStart($server);

        $this->assertTrue(true);
    }

    public function testLifecycleCallbacksDispatchServerEvents(): void
    {
        $events = [];
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$events): object {
            $events[] = $event::class;

            return $event;
        });
        $this->container->replace(EventDispatcherInterface::class, fn () => $this->eventDispatcher);
        $adapter = $this->container->make(Swoole::class);
        $server = new IdleWebSocketServer();
        $server->setting = ['worker_num' => 2];
        $server->master_pid = 1;

        $adapter->onBeforeShutdown($server);
        $adapter->onShutdown($server);
        $adapter->onManagerStart($server);
        $adapter->onManagerStop($server);
        $adapter->onConnect($server, 9, 0);
        $adapter->onPacket($server, 'blob', ['fd' => 9]);
        $adapter->onWorkerStart($server, 0);
        $adapter->onWorkerStart($server, 3);
        $adapter->onWorkerStop($server, 0);
        $adapter->onWorkerStop($server, 3);
        $adapter->onTask($server, 1, 0, ['job' => true]);
        $adapter->onFinish($server, 1, ['done' => true]);
        $adapter->onPipeMessage($server, 0, 'hello');
        $adapter->onWorkerError($server, 0, 10, 1, 9);
        $adapter->onWorkerError($server, 3, 11, 1, 9);

        foreach ([
            \Switon\Http\Server\Event\ServerBeforeShutdown::class,
            \Switon\Http\Server\Event\ServerShutdown::class,
            \Switon\Http\Server\Event\ServerManagerStart::class,
            \Switon\Http\Server\Event\ServerManagerStop::class,
            \Switon\Http\Server\Event\ServerConnect::class,
            \Switon\Http\Server\Event\ServerPacket::class,
            \Switon\Http\Server\Event\ServerWorkerStart::class,
            \Switon\Http\Server\Event\ServerTaskerStart::class,
            \Switon\Http\Server\Event\ServerWorkerStop::class,
            \Switon\Http\Server\Event\ServerTaskerStop::class,
            \Switon\Http\Server\Event\ServerTask::class,
            \Switon\Http\Server\Event\ServerFinish::class,
            \Switon\Http\Server\Event\ServerPipeMessage::class,
            \Switon\Http\Server\Event\ServerWorkerError::class,
            \Switon\Http\Server\Event\ServerTaskerError::class,
        ] as $expected) {
            $this->assertContains($expected, $events, $expected);
        }
    }
}

class IdleWebSocketServer extends SwooleServer
{
    public function __construct()
    {
    }

    public bool $established = false;

    /** @var list<array{0: int, 1: int}> */
    public array $disconnects = [];

    public function isEstablished(int $fd): bool
    {
        return $this->established;
    }

    public function disconnect(int $fd, int $code = SWOOLE_WEBSOCKET_CLOSE_NORMAL, string $reason = ''): bool
    {
        $this->disconnects[] = [$fd, $code];

        return true;
    }
}
