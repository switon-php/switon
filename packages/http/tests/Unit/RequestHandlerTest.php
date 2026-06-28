<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Http\Event\RequestReceived;
use Switon\Http\RequestHandlerInterface;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Http\ServerInterface;
use Switon\Http\Tests\TestCase;
use Switon\Invoking\InvokerInterface;
use Switon\Routing\MatcherInterface;
use Switon\Routing\RouterInterface;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class RequestHandlerTest extends TestCase
{
    #[Autowired] protected RequestHandlerInterface $handler;
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ResponseInterface $response;
    #[Autowired] protected RouterInterface $router;
    protected MatcherInterface $matcher; // Not autowired - set manually in setUp
    #[Autowired] protected InvokerInterface $invoker;
    #[Autowired] protected ListenerProviderInterface $listenerProvider;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected ServerInterface $httpServer;

    protected function beforeSetUpHttpContainer(): void
    {
        // Set up ListenerProviderInterface BEFORE ContextManager is created
        // (ContextManager might trigger RequestHandler creation which needs ListenerProviderInterface)
        $this->listenerProvider = $this->createMock(ListenerProviderInterface::class);
        $this->container->remove(ListenerProviderInterface::class);
        $this->container->replace(ListenerProviderInterface::class, $this->listenerProvider);

        // Set up InputInterface BEFORE any service that might need it
        // InputInterface is mapped to RequestInterface by ServiceProvider
        $this->container->replace(\Switon\Core\InputInterface::class, \Switon\Http\RequestInterface::class);

        // Set up ServerInterface mock BEFORE property autowiring to prevent container from resolving to Swoole
        // This ensures RequestHandler (injected in parent::setUp()) gets the mock instead of real Swoole instance
        $this->httpServer = $this->createMock(ServerInterface::class);
        $this->httpServer->method('write')->willReturn(true);
        $this->container->remove(ServerInterface::class);
        $this->container->replace(ServerInterface::class, $this->httpServer);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Replace default MockEventDispatcher with mock for event verification
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->container->remove(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $this->container->replace(\Psr\EventDispatcher\EventDispatcherInterface::class, $this->eventDispatcher);
        // Also remove Switon-specific interface mapping if it exists
        $this->container->remove(\Switon\Eventing\EventDispatcherInterface::class);

        // ContextManagerInterface is already set by parent::setUp()
        // ClockInterface is already set by setUpHttpContainer()
        // PathAliasInterface is already configured by Switon\Testing\Container with @view alias

        $this->router = $this->createMock(RouterInterface::class);
        // Set default return value for getPrefix() method (used in invoke() when rendering views)
        $this->router->method('getPrefix')->willReturn('');
        $this->container->replace(RouterInterface::class, $this->router);

        // ServerInterface is already set in beforeSetUpHttpContainer() to prevent Swoole resolution

        $this->container->replace(LoggerInterface::class, $this->createStub(LoggerInterface::class));
        // Don't replace RendererInterface - ExceptionDispatcher needs real Renderer instance
        // Renderer will use PathAliasInterface which has @view alias set above

        // InputInterface is already set in beforeSetUpHttpContainer()

        $this->matcher = $this->createMock(MatcherInterface::class);
        $this->container->replace(MatcherInterface::class, $this->matcher);

        $this->invoker = $this->createMock(InvokerInterface::class);
        $this->container->replace(InvokerInterface::class, $this->invoker);

        // ListenerProviderInterface is already set in beforeSetUpHttpContainer()

        // ExceptionDispatcherInterface is auto-mapped by convention (ExceptionDispatcher).
        // No explicit replace() call needed.

        // Remove RequestHandler if it was already resolved (to force re-injection with new mocks)
        if ($this->container->has(RequestHandlerInterface::class)) {
            $this->container->remove(RequestHandlerInterface::class);
        }

        // Re-inject RequestHandler after all dependencies are set up
        // This ensures RequestHandler uses the correct mocks (especially EventDispatcher)
        // Property autowiring is automatically performed by parent::setUp()
        $this->injector->inject($this);

        // Resolve RequestHandler after dependencies are configured
        $this->handler = $this->container->get(RequestHandlerInterface::class);

        // Boot handler to register filters and transformers as event listeners.
        // This is necessary for NormalizeActionReturnTransformer and RequestIdFilter to work.
        $this->handler->boot();
    }

    protected function createRequestEvent(
        string $method = 'GET',
        string $uri = '/test',
        array  $get = [],
        array  $post = [],
        array  $server = [],
        string $rawBody = '',
        array  $cookie = [],
        array  $files = []
    ): RequestReceived {
        $server['REQUEST_METHOD'] = $method;
        $server['REQUEST_URI'] = $uri;
        return new RequestReceived(
            sender: $this->httpServer,
            GET: $get,
            POST: $post,
            SERVER: $server,
            RAW_BODY: $rawBody,
            COOKIE: $cookie,
            FILES: $files
        );
    }

    public function testBootRegistersFiltersAndTransformers(): void
    {
        $this->listenerProvider->expects($this->exactly(2))
            ->method('register')
            ->with($this->logicalOr(
                $this->isInstanceOf(\Switon\Http\Transformer\NormalizeActionReturnTransformer::class),
                $this->isInstanceOf(\Switon\Http\Filter\RequestIdFilter::class)
            ));

        $this->handler->boot();
    }

    public function testHandleThrowsNotFoundRouteExceptionWhenRouteNotFound(): void
    {
        $requestEvent = $this->createRequestEvent('GET', '/not-found');
        $this->request->onRequestReceived($requestEvent);

        $call = 0;
        $this->router->expects($this->exactly(5))
            ->method('match')
            ->willReturnCallback(function (string $uri, string $verb) use (&$call): mixed {
                $this->assertSame('/not-found', $uri);
                $call++;

                $expectedVerbs = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
                $this->assertSame($expectedVerbs[$call - 1], $verb);

                throw \Switon\Routing\Exception\NotFoundRouteException::of('Route not found');
            });

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnArgument(0); // Return the event being dispatched

        $this->handler->handle();

        $statusCode = $this->response->getStatusCode();
        $this->assertContains($statusCode, [404, 500], "Status code should be 404 or 500, got {$statusCode}");
    }

    public function testHandleReturnsMethodNotAllowedWhenOppositeCommonVerbMatches(): void
    {
        $requestEvent = $this->createRequestEvent('POST', '/users');
        $this->request->onRequestReceived($requestEvent);

        $call = 0;
        $this->router->expects($this->exactly(2))
            ->method('match')
            ->willReturnCallback(function (string $uri, string $verb) use (&$call): mixed {
                $this->assertSame('/users', $uri);
                $call++;

                if ($call === 1) {
                    $this->assertSame('POST', $verb);
                    throw \Switon\Routing\Exception\NotFoundRouteException::of('Route not found');
                }

                $this->assertSame('GET', $verb);
                return $this->matcher;
            });

        $this->matcher->method('getHandler')
            ->willReturn('UserController::index');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnArgument(0);

        $this->handler->handle();

        $this->assertSame(405, $this->response->getStatusCode());
    }

    public function testHandleReturnsMethodNotAllowedForNonGetPostVerbs(): void
    {
        $requestEvent = $this->createRequestEvent('DELETE', '/users');
        $this->request->onRequestReceived($requestEvent);

        $call = 0;
        $this->router->expects($this->exactly(4))
            ->method('match')
            ->willReturnCallback(function (string $uri, string $verb) use (&$call): mixed {
                $this->assertSame('/users', $uri);
                $call++;

                return match ($call) {
                    1 => $this->throwNotFound($verb, 'DELETE'),
                    2 => $this->throwNotFound($verb, 'GET'),
                    3 => $this->throwNotFound($verb, 'POST'),
                    4 => $this->matcher,
                };
            });

        $this->matcher->method('getHandler')
            ->willReturn('UserController::update');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnArgument(0);

        $this->handler->handle();

        $this->assertSame(405, $this->response->getStatusCode());
    }

    public function testHandleThrowsControllerNotFoundExceptionWhenControllerNotFound(): void
    {
        $requestEvent = $this->createRequestEvent();
        $this->request->onRequestReceived($requestEvent);

        $this->matcher->method('getHandler')
            ->willReturn('NonExistentController::action');

        $this->matcher->method('getVariables')
            ->willReturn([]);

        $this->router->expects($this->once())
            ->method('match')
            ->with($this->anything(), $this->anything()) // URI and verb
            ->willReturn($this->matcher);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnArgument(0); // Return the event being dispatched

        $this->handler->handle();

        $this->assertSame(404, $this->response->getStatusCode());
    }

    public function testHandleThrowsActionNotFoundExceptionWhenActionNotFound(): void
    {
        $requestEvent = $this->createRequestEvent();
        $this->request->onRequestReceived($requestEvent);

        // Use a real class that exists (TestCase)
        $this->matcher->method('getHandler')
            ->willReturn(TestCase::class . '::nonExistentMethod');

        $this->matcher->method('getVariables')
            ->willReturn([]);

        $this->router->expects($this->once())
            ->method('match')
            ->with($this->anything(), $this->anything()) // URI and verb
            ->willReturn($this->matcher);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnArgument(0); // Return the event being dispatched

        $this->handler->handle();

        $this->assertSame(404, $this->response->getStatusCode());
    }

    protected function throwNotFound(string $actualVerb, string $expectedVerb): never
    {
        $this->assertSame($expectedVerb, $actualVerb);
        throw \Switon\Routing\Exception\NotFoundRouteException::of('Route not found');
    }

    public function testHandleFailsWhenRouteHandlerStringHasEmptyActionPart(): void
    {
        $requestEvent = $this->createRequestEvent('GET', '/broken');
        $this->request->onRequestReceived($requestEvent);

        $this->matcher->method('getHandler')->willReturn(self::class . '::');
        $this->matcher->method('getVariables')->willReturn([]);

        $this->router->expects($this->once())
            ->method('match')
            ->willReturn($this->matcher);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnArgument(0);

        $this->invoker->expects($this->never())->method('invoke');

        $this->handler->handle();

        $this->assertNotSame(200, $this->response->getStatusCode());
    }

}
