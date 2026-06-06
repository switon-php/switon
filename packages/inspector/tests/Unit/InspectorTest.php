<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\App;
use Switon\Core\AppInterface;
use Switon\Core\CollectorInterface;
use Switon\Core\ContextManagerInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Http\Event\RequestBegin;
use Switon\Http\Event\RequestEnd;
use Switon\Http\Event\ResponseStringify;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Http\UrlGeneratorInterface;
use Switon\Inspector\CollectorDiscoveryInterface;
use Switon\Inspector\Event\InspectorLinkGenerated;
use Switon\Inspector\Inspector;
use Switon\Inspector\InspectorContext;
use Switon\Inspector\StorageInterface;
use Switon\Inspector\Tests\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class InspectorTest extends TestCase
{
    protected ListenerProviderInterface $listenerProvider;
    protected CollectorDiscoveryInterface $discovery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listenerProvider = $this->createMock(ListenerProviderInterface::class);
        $this->container->set(ListenerProviderInterface::class, $this->listenerProvider);

        $this->discovery = $this->createMock(CollectorDiscoveryInterface::class);
        $this->discovery->method('resolve')->willReturn([]);
        $this->container->set(CollectorDiscoveryInterface::class, $this->discovery);

        $this->container->set(EventDispatcherInterface::class, $this->createMock(EventDispatcherInterface::class));
        $this->container->set(RequestInterface::class, $this->createMock(RequestInterface::class));
        $this->container->set(ResponseInterface::class, $this->createMock(ResponseInterface::class));
        $this->container->set(StorageInterface::class, $this->createMock(StorageInterface::class));
        $this->container->set(UrlGeneratorInterface::class, $this->createMock(UrlGeneratorInterface::class));
    }

    protected function makeInspector(string $env = 'dev', array $extra = []): Inspector
    {
        // Replace AppInterface with fresh App config
        // String binding (AppInterface => App::class) creates App with defaults,
        // so we use array binding to pass env/id parameters directly
        $this->container->replace(AppInterface::class, ['class' => App::class, 'env' => $env, 'id' => 'test-app']);

        return $this->container->make(Inspector::class, $extra);
    }

    public function testBootRegistersListenersWhenEnabled(): void
    {
        // Arrange
        $inspector = $this->makeInspector('dev');

        $this->listenerProvider->expects($this->atLeastOnce())
            ->method('register');

        // Act
        $inspector->boot();
    }

    public function testBootRegistersListenersInTestEnv(): void
    {
        $inspector = $this->makeInspector('test');

        $this->listenerProvider->expects($this->atLeastOnce())
            ->method('register');

        $inspector->boot();
    }

    public function testBootBootsCollectors(): void
    {
        // Arrange
        $collector = $this->createMock(CollectorInterface::class);
        $collector->expects($this->once())->method('boot');

        $this->discovery = $this->createMock(CollectorDiscoveryInterface::class);
        $this->discovery->method('resolve')->willReturn(['test' => $collector]);
        $this->container->replace(CollectorDiscoveryInterface::class, $this->discovery);

        $inspector = $this->makeInspector('dev');

        // Act
        $inspector->boot();
    }

    public function testBootDoesNotRegisterListenersWhenDisabled(): void
    {
        // Arrange
        $inspector = $this->makeInspector('production');

        $this->listenerProvider->expects($this->never())->method('register');
        $this->listenerProvider->expects($this->never())->method('on');

        // Act
        $inspector->boot();
    }

    public function testBootRegistersListenersInProductionWhenExplicitlyEnabled(): void
    {
        $inspector = $this->makeInspector('production', ['enabled' => true]);

        $this->listenerProvider->expects($this->atLeastOnce())
            ->method('register');

        $inspector->boot();
    }

    public function testBootRespectsExplicitEnabledFalse(): void
    {
        // Arrange - env=dev would normally enable, but explicit false overrides
        $inspector = $this->makeInspector('dev', ['enabled' => false]);

        $this->listenerProvider->expects($this->never())->method('register');

        // Act
        $inspector->boot();
    }

    public function testOnRequestBeginGeneratesKeyAndUrlWhenEnabled(): void
    {
        // Arrange
        $inspector = $this->makeInspector('dev');
        $inspector->boot();

        $request = $this->container->get(RequestInterface::class);
        $response = $this->container->get(ResponseInterface::class);
        $urlGenerator = $this->container->get(UrlGeneratorInterface::class);
        $eventDispatcher = $this->container->get(EventDispatcherInterface::class);

        $request->method('header')->with('user-agent')->willReturn('Mozilla/5.0');
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with($this->matchesRegularExpression('#/\?__inspector=/\d{6}/\d{6}_[a-zA-Z0-9]{32}\.html#'), true)
            ->willReturn('http://example.com/?__inspector=/240207/120000_abc123.html');

        $response->expects($this->once())
            ->method('setHeader')
            ->with('X-Inspector-Link', 'http://example.com/?__inspector=/240207/120000_abc123.html');

        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(InspectorLinkGenerated::class));

        // Act
        $event = new RequestBegin($request);
        $inspector->onRequestBegin($event);

        // Assert
        $context = $inspector->getContext();
        $this->assertTrue($context->enabled);
        $this->assertNotEmpty($context->key);
        $this->assertNotEmpty($context->url);
    }

    public function testOnRequestBeginDisablesForApacheBench(): void
    {
        // Arrange
        $inspector = $this->makeInspector('dev');
        $inspector->boot();

        $request = $this->container->get(RequestInterface::class);
        $response = $this->container->get(ResponseInterface::class);
        $eventDispatcher = $this->container->get(EventDispatcherInterface::class);

        $request->method('header')->with('user-agent')->willReturn('ApacheBench/2.3');

        $response->expects($this->never())->method('setHeader');
        $eventDispatcher->expects($this->never())->method('dispatch');

        // Act
        $event = new RequestBegin($request);
        $inspector->onRequestBegin($event);

        // Assert
        $context = $inspector->getContext();
        $this->assertFalse($context->enabled);
    }

    public function testOnRequestBeginLowercaseApacheBenchSubstringDoesNotDisable(): void
    {
        $inspector = $this->makeInspector('dev');
        $inspector->boot();

        $request = $this->container->get(RequestInterface::class);
        $response = $this->container->get(ResponseInterface::class);
        $urlGenerator = $this->container->get(UrlGeneratorInterface::class);
        $eventDispatcher = $this->container->get(EventDispatcherInterface::class);

        $request->method('header')->with('user-agent')->willReturn('apachebench/2.3');

        $urlGenerator->method('generate')->willReturn('http://example.com/?__inspector=/stub.html');
        $response->expects($this->once())->method('setHeader');
        $eventDispatcher->expects($this->once())->method('dispatch');

        $inspector->onRequestBegin(new RequestBegin($request));

        $this->assertTrue($inspector->getContext()->enabled);
    }

    public function testOnRequestBeginWithNullUserAgentRunsNormalEnablePath(): void
    {
        $inspector = $this->makeInspector('dev');
        $inspector->boot();

        $request = $this->container->get(RequestInterface::class);
        $response = $this->container->get(ResponseInterface::class);
        $urlGenerator = $this->container->get(UrlGeneratorInterface::class);
        $eventDispatcher = $this->container->get(EventDispatcherInterface::class);

        $request->method('header')->with('user-agent')->willReturn(null);

        $urlGenerator->method('generate')->willReturn('http://example.com/?__inspector=/stub.html');
        $response->expects($this->once())->method('setHeader');
        $eventDispatcher->expects($this->once())->method('dispatch');

        $inspector->onRequestBegin(new RequestBegin($request));

        $this->assertTrue($inspector->getContext()->enabled);
    }

    public function testOnRequestEndStoresDataWhenEnabled(): void
    {
        // Arrange
        $inspector = $this->makeInspector('dev');
        $inspector->boot();

        $request = $this->container->get(RequestInterface::class);
        $storage = $this->container->get(StorageInterface::class);
        $contextManager = $this->container->get(ContextManagerInterface::class);

        // Enable inspector in context
        $context = $contextManager->getContext($inspector);
        $context->enabled = true;
        $context->key = 'test-key-123';
        $context->url = 'http://example.com/?__inspector=test-key-123.html';

        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('collect')->willReturn(['test' => 'data']);

        $this->discovery = $this->createMock(CollectorDiscoveryInterface::class);
        $this->discovery->method('resolve')->willReturn(['test' => $collector]);
        $this->container->replace(CollectorDiscoveryInterface::class, $this->discovery);

        // Re-inject discovery
        $this->injector->inject($inspector);

        $storage->expects($this->once())
            ->method('write')
            ->with('test-key-123', ['test' => ['test' => 'data']]);

        $storage->expects($this->once())
            ->method('broadcastLink')
            ->with('http://example.com/?__inspector=test-key-123.html');

        // Act
        $event = new RequestEnd($request, $this->container->get(ResponseInterface::class));
        $inspector->onRequestEnd($event);
    }

    public function testOnRequestEndSkipsWhenDisabled(): void
    {
        // Arrange
        $inspector = $this->makeInspector('dev');
        $inspector->boot();

        $request = $this->container->get(RequestInterface::class);
        $storage = $this->container->get(StorageInterface::class);
        $contextManager = $this->container->get(ContextManagerInterface::class);

        // Disable inspector in context
        $context = $contextManager->getContext($inspector);
        $context->enabled = false;

        $storage->expects($this->never())->method('write');
        $storage->expects($this->never())->method('broadcastLink');

        // Act
        $event = new RequestEnd($request, $this->container->get(ResponseInterface::class));
        $inspector->onRequestEnd($event);
    }

    public function testOnRequestEndPropagatesCollectorExceptionAndSkipsStorageWrite(): void
    {
        // Arrange
        $inspector = $this->makeInspector('dev');
        $inspector->boot();

        $request = $this->container->get(RequestInterface::class);
        $storage = $this->container->get(StorageInterface::class);
        $contextManager = $this->container->get(ContextManagerInterface::class);

        $context = $contextManager->getContext($inspector);
        $context->enabled = true;
        $context->key = 'collector-fail-key';
        $context->url = 'http://example.com/?__inspector=collector-fail-key.html';

        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('collect')->willThrowException(new RuntimeException('collector failed'));
        $this->discovery = $this->createMock(CollectorDiscoveryInterface::class);
        $this->discovery->method('resolve')->willReturn(['broken' => $collector]);
        $this->container->replace(CollectorDiscoveryInterface::class, $this->discovery);
        $this->injector->inject($inspector);

        $storage->expects($this->never())->method('write');
        $storage->expects($this->never())->method('broadcastLink');

        // Act & Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('collector failed');
        $inspector->onRequestEnd(new RequestEnd($request, $this->container->get(ResponseInterface::class)));
    }

    public function testOnResponseStringifyAppendsLinkWhenTailIsTrueAndContentIsArray(): void
    {
        // Arrange
        $inspector = $this->makeInspector('dev', ['tail' => true]);
        $inspector->boot();

        $response = $this->container->get(ResponseInterface::class);
        $response->method('getContent')->willReturn(['key' => 'value']);
        $response->method('getHeader')->with('X-Inspector-Link')->willReturn('http://example.com/inspector.html');

        $response->expects($this->once())
            ->method('setContent')
            ->with(['key' => 'value', 'inspector' => 'http://example.com/inspector.html']);

        // Act
        $event = new ResponseStringify($response);
        $inspector->onResponseStringify($event);
    }

    public function testOnResponseStringifySkipsWhenTailIsFalse(): void
    {
        // Arrange
        $inspector = $this->makeInspector('dev', ['tail' => false]);
        $inspector->boot();

        $response = $this->container->get(ResponseInterface::class);
        $response->method('getContent')->willReturn(['key' => 'value']);

        $response->expects($this->never())->method('setContent');

        // Act
        $event = new ResponseStringify($response);
        $inspector->onResponseStringify($event);
    }

    public function testOnResponseStringifySkipsWhenContentIsNotArray(): void
    {
        // Arrange
        $inspector = $this->makeInspector('dev', ['tail' => true]);
        $inspector->boot();

        $response = $this->container->get(ResponseInterface::class);
        $response->method('getContent')->willReturn('string content');

        $response->expects($this->never())->method('setContent');

        // Act
        $event = new ResponseStringify($response);
        $inspector->onResponseStringify($event);
    }

    public function testOnResponseStringifyAppendsNullInspectorWhenHeaderMissing(): void
    {
        // Arrange
        $inspector = $this->makeInspector('dev', ['tail' => true]);
        $inspector->boot();

        $response = $this->container->get(ResponseInterface::class);
        $response->method('getContent')->willReturn(['ok' => true]);
        $response->method('getHeader')->with('X-Inspector-Link')->willReturn(null);

        $response->expects($this->once())
            ->method('setContent')
            ->with(['ok' => true, 'inspector' => null]);

        // Act
        $event = new ResponseStringify($response);
        $inspector->onResponseStringify($event);
    }

    public function testGetContextReturnsInspectorContext(): void
    {
        // Arrange
        $inspector = $this->makeInspector('dev');

        // Act
        $context = $inspector->getContext();

        // Assert
        $this->assertInstanceOf(InspectorContext::class, $context);
    }

    public function testOnServerReadyIsCallable(): void
    {
        // Arrange
        $inspector = $this->makeInspector('dev');

        // Act & Assert - should not throw
        $inspector->onServerReady();
        $this->addToAssertionCount(1);
    }
}
