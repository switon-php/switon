<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Switon\Binding\ArgumentsBinderInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Http\Event\RequestReceived;
use Switon\Http\RequestHandlerInterface;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Http\ServerInterface;
use Switon\Http\Tests\TestCase;
use Switon\Core\StopFlow;
use Switon\Invoking\InvokerInterface;
use Switon\Routing\Attribute\ViewGetMapping;
use Switon\Routing\Attribute\ViewMapping;
use Switon\Routing\MatcherInterface;
use Switon\Routing\RouterInterface;

#[AllowMockObjectsWithoutExpectations]
class RequestHandlerInvokeTest extends TestCase
{
    #[Autowired] protected RequestHandlerInterface $handler;
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ResponseInterface $response;
    #[Autowired] protected RouterInterface $router;
    protected MatcherInterface $matcher;
    #[Autowired] protected InvokerInterface $invoker;
    #[Autowired] protected ListenerProviderInterface $listenerProvider;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected ServerInterface $httpServer;
    #[Autowired] protected ArgumentsBinderInterface $argumentsBinder;

    protected function beforeSetUpHttpContainer(): void
    {
        $this->listenerProvider = $this->createMock(ListenerProviderInterface::class);
        $this->container->remove(ListenerProviderInterface::class);
        $this->container->replace(ListenerProviderInterface::class, $this->listenerProvider);
        $this->container->replace(\Switon\Core\InputInterface::class, \Switon\Http\RequestInterface::class);

        $this->httpServer = $this->createMock(ServerInterface::class);
        $this->httpServer->method('write')->willReturn(true);
        $this->container->remove(ServerInterface::class);
        $this->container->replace(ServerInterface::class, $this->httpServer);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->container->remove(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $this->container->replace(\Psr\EventDispatcher\EventDispatcherInterface::class, $this->eventDispatcher);
        $this->container->remove(\Switon\Eventing\EventDispatcherInterface::class);

        $this->router = $this->createMock(RouterInterface::class);
        $this->router->method('getPrefix')->willReturn('');
        $this->container->replace(RouterInterface::class, $this->router);

        $this->matcher = $this->createMock(MatcherInterface::class);
        $this->container->replace(MatcherInterface::class, $this->matcher);

        $this->invoker = $this->createMock(InvokerInterface::class);
        $this->container->replace(InvokerInterface::class, $this->invoker);

        $this->argumentsBinder = $this->createStub(ArgumentsBinderInterface::class);
        $this->argumentsBinder->method('resolve')->willReturn([]);
        $this->container->replace(ArgumentsBinderInterface::class, $this->argumentsBinder);

        $this->container->replace(LoggerInterface::class, $this->createStub(LoggerInterface::class));

        $this->response = $this->createMock(ResponseInterface::class);
        $this->container->replace(ResponseInterface::class, $this->response);

        if ($this->container->has(RequestHandlerInterface::class)) {
            $this->container->remove(RequestHandlerInterface::class);
        }

        $this->injector->inject($this);
        $this->handler = $this->container->get(RequestHandlerInterface::class);
        $this->handler->boot();
    }

    protected function receiveRequest(
        string $method = 'GET',
        string $uri = '/ping',
        array $get = [],
        array $post = [],
        array $server = [],
    ): void {
        $this->request->onRequestReceived(new RequestReceived(
            sender: $this->httpServer,
            GET: $get,
            POST: $post,
            SERVER: array_merge(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri], $server),
            RAW_BODY: '',
            COOKIE: [],
            FILES: [],
        ));
    }

    public function testHandleInvokesMatchedActionAndMergesRouteVariables(): void
    {
        $controller = new RequestHandlerPingController();
        $this->container->set(RequestHandlerPingController::class, $controller);

        $this->receiveRequest();

        $this->matcher->method('getHandler')->willReturn(RequestHandlerPingController::class . '::ping');
        $this->matcher->method('getVariables')->willReturn(['id' => '7']);

        $this->router->expects($this->once())->method('match')->willReturn($this->matcher);
        $this->invoker->expects($this->once())
            ->method('invoke')
            ->willReturn(['pong' => true]);

        $this->response->method('getContent')->willReturn(['pong' => true]);
        $this->response->method('isChunked')->willReturn(false);

        $this->eventDispatcher->expects($this->atLeastOnce())->method('dispatch')->willReturnArgument(0);

        $this->httpServer->expects($this->once())->method('sendHeaders');
        $this->httpServer->expects($this->once())->method('sendBody');

        $this->handler->handle();

        $this->assertSame('7', $this->request->getContext()->_REQUEST['id']);
    }

    public function testHandleStringifiesArrayResponseContent(): void
    {
        $this->container->set(RequestHandlerPingController::class, new RequestHandlerPingController());
        $this->receiveRequest();

        $this->matcher->method('getHandler')->willReturn(RequestHandlerPingController::class . '::ping');
        $this->matcher->method('getVariables')->willReturn([]);
        $this->router->method('match')->willReturn($this->matcher);

        $this->invoker->method('invoke')->willReturn(['ok' => 1]);

        $this->response->method('getContent')->willReturnOnConsecutiveCalls(['ok' => 1], ['ok' => 1]);
        $this->response->method('isChunked')->willReturn(false);
        $this->response->expects($this->once())->method('setContent')->with('{"ok":1}');

        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->httpServer->method('sendHeaders');
        $this->httpServer->method('sendBody');

        $this->handler->handle();
    }

    public function testHandleFlushesChunkedResponsesViaWrite(): void
    {
        $this->container->set(RequestHandlerPingController::class, new RequestHandlerPingController());
        $this->receiveRequest();

        $this->matcher->method('getHandler')->willReturn(RequestHandlerPingController::class . '::ping');
        $this->matcher->method('getVariables')->willReturn([]);
        $this->router->method('match')->willReturn($this->matcher);
        $this->invoker->method('invoke')->willReturn(null);

        $this->response->method('getContent')->willReturn('partial');
        $this->response->method('isChunked')->willReturn(true);

        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->httpServer->expects($this->once())->method('write')->with('');
        $this->httpServer->expects($this->never())->method('sendHeaders');

        $this->handler->handle();
    }

    public function testHandleRendersHtmlForViewMappingOnGet(): void
    {
        $this->container->set(ViewHtmlController::class, new ViewHtmlController());
        $this->receiveRequest('GET', '/page', [], [], ['HTTP_ACCEPT' => 'text/html']);

        $this->matcher->method('getHandler')->willReturn(ViewHtmlController::class . '::page');
        $this->matcher->method('getVariables')->willReturn([]);
        $this->router->method('match')->willReturn($this->matcher);

        $this->invoker->expects($this->once())->method('invoke')->willReturn(['title' => 'home']);
        $this->response->method('getContent')->willReturn('');
        $this->response->method('isChunked')->willReturn(false);

        $dispatched = [];
        $this->eventDispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$dispatched): object {
            $dispatched[] = $event::class;

            return $event;
        });
        $this->httpServer->method('sendHeaders');
        $this->httpServer->method('sendBody');

        $this->handler->handle();

        $this->assertContains(\Switon\Http\Event\RequestRendering::class, $dispatched);
        $this->assertContains(\Switon\Http\Event\RequestRendered::class, $dispatched);
    }

    public function testHandleViewGetMappingSkipsInvokeForHtmlClients(): void
    {
        $this->container->set(ViewGetHtmlController::class, new ViewGetHtmlController());
        $this->receiveRequest('GET', '/article', [], [], ['HTTP_ACCEPT' => 'text/html']);

        $this->matcher->method('getHandler')->willReturn(ViewGetHtmlController::class . '::show');
        $this->matcher->method('getVariables')->willReturn([]);
        $this->router->method('match')->willReturn($this->matcher);

        $this->invoker->expects($this->never())->method('invoke');
        $this->response->method('getContent')->willReturn('');
        $this->response->method('isChunked')->willReturn(false);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->httpServer->method('sendHeaders');
        $this->httpServer->method('sendBody');

        $this->handler->handle();
    }

    public function testHandleViewGetMappingInvokesForJsonClients(): void
    {
        $this->container->set(ViewGetHtmlController::class, new ViewGetHtmlController());
        $this->receiveRequest('GET', '/article', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->matcher->method('getHandler')->willReturn(ViewGetHtmlController::class . '::show');
        $this->matcher->method('getVariables')->willReturn([]);
        $this->router->method('match')->willReturn($this->matcher);

        $this->invoker->expects($this->once())->method('invoke')->willReturn(['id' => 1]);
        $this->response->method('getContent')->willReturn(['id' => 1]);
        $this->response->method('isChunked')->willReturn(false);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->httpServer->method('sendHeaders');
        $this->httpServer->method('sendBody');

        $this->handler->handle();
    }

    public function testHandleSwallowsStopFlowWithoutDispatchingFailure(): void
    {
        $this->container->set(RequestHandlerPingController::class, new RequestHandlerPingController());
        $this->receiveRequest();

        $this->matcher->method('getHandler')->willReturn(RequestHandlerPingController::class . '::ping');
        $this->matcher->method('getVariables')->willReturn([]);
        $this->router->method('match')->willReturn($this->matcher);

        $this->invoker->method('invoke')->willThrowException(StopFlow::because('cache hit'));
        $this->response->method('getContent')->willReturn('');
        $this->response->method('isChunked')->willReturn(false);

        $failed = false;
        $this->eventDispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$failed): object {
            if ($event instanceof \Switon\Http\Event\RequestFailed) {
                $failed = true;
            }

            return $event;
        });
        $this->httpServer->method('sendHeaders');
        $this->httpServer->method('sendBody');

        $this->handler->handle();

        $this->assertFalse($failed);
    }

    public function testHandleRejectsMalformedHandlerStrings(): void
    {
        $this->receiveRequest('GET', '/broken');

        $this->matcher->method('getHandler')->willReturn('::ping');
        $this->matcher->method('getVariables')->willReturn([]);
        $this->router->method('match')->willReturn($this->matcher);

        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->invoker->expects($this->never())->method('invoke');

        $this->handler->handle();

        $this->assertNotSame(200, $this->response->getStatusCode());
    }
}

class RequestHandlerPingController
{
    public function ping(): array
    {
        return ['pong' => true];
    }
}

class ViewHtmlController
{
    #[ViewMapping('/page')]
    public function page(): array
    {
        return ['title' => 'home'];
    }
}

class ViewGetHtmlController
{
    #[ViewGetMapping('/article')]
    public function show(): array
    {
        return ['id' => 1];
    }
}
