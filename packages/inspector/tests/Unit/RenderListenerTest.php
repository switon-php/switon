<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\Json;
use Switon\Core\StopFlow;
use Switon\Http\Event\RequestBegin;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Inspector\HtmlRendererInterface;
use Switon\Inspector\Listener\RenderListener;
use Switon\Inspector\StorageInterface;
use Switon\Inspector\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class RenderListenerTest extends TestCase
{
    protected RequestInterface $request;
    protected ResponseInterface $response;
    protected StorageInterface $storage;
    protected HtmlRendererInterface $htmlRenderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(RequestInterface::class);
        $this->container->replace(RequestInterface::class, $this->request);

        $this->response = $this->createMock(ResponseInterface::class);
        $this->container->replace(ResponseInterface::class, $this->response);

        $this->storage = $this->createMock(StorageInterface::class);
        $this->container->replace(StorageInterface::class, $this->storage);

        $this->htmlRenderer = $this->createMock(HtmlRendererInterface::class);
        $this->container->replace(HtmlRendererInterface::class, $this->htmlRenderer);
    }

    protected function makeListener(): RenderListener
    {
        // Container will auto-inject the mocks we registered in setUp()
        return $this->container->make(RenderListener::class);
    }

    public function testOnRequestBeginReturnsEarlyWhenNoInspectorParameter(): void
    {
        // Arrange
        $listener = $this->makeListener();
        $this->request->method('get')->with('__inspector')->willReturn(null);

        $this->storage->expects($this->never())->method('read');
        $this->response->expects($this->never())->method('text');
        $this->response->expects($this->never())->method('json');

        // Act
        $event = new RequestBegin($this->request);
        $listener->onRequestBegin($event);
    }

    public function testOnRequestBeginReturnsEarlyWhenParameterDoesNotMatchPattern(): void
    {
        // Arrange
        $listener = $this->makeListener();
        $this->request->method('get')->with('__inspector')->willReturn('invalid-format');

        $this->storage->expects($this->never())->method('read');

        // Act
        $event = new RequestBegin($this->request);
        $listener->onRequestBegin($event);
    }

    public function testOnRequestBeginRendersHtmlWhenDataExists(): void
    {
        // Arrange
        $key = 'test-key-123';
        $data = '{"test": "data"}';
        $html = '<html>Inspector UI</html>';

        // Set up all mocks BEFORE creating listener
        $this->request->method('get')->with('__inspector')->willReturn("$key.html");
        $this->storage->method('read')->with($key)->willReturn($data);
        $this->htmlRenderer->method('render')->with($data)->willReturn($html);
        $this->response->method('text')
            ->willReturn($this->response);

        // Create listener after mocks are configured
        $listener = $this->makeListener();

        // Act & Assert
        $event = new RequestBegin($this->request);
        $this->expectException(StopFlow::class);
        $this->expectExceptionMessage('Inspector UI displayed');
        $listener->onRequestBegin($event);
    }

    public function testOnRequestBeginRendersJsonWhenDataExists(): void
    {
        // Arrange
        $key = 'test-key-123';
        $data = '{"test": "data"}';
        $parsedData = Json::parse($data);

        // Set up all mocks BEFORE creating listener
        $this->request->method('get')->with('__inspector')->willReturn("$key.json");
        $this->storage->method('read')->with($key)->willReturn($data);
        $this->response->method('json')
            ->willReturn($this->response);

        // Create listener after mocks are configured
        $listener = $this->makeListener();

        // Act & Assert
        $event = new RequestBegin($this->request);
        $this->expectException(StopFlow::class);
        $this->expectExceptionMessage('Inspector UI displayed');
        $listener->onRequestBegin($event);
    }

    public function testOnRequestBeginRendersTxtWhenDataExists(): void
    {
        // Arrange
        $key = 'test-key-123';
        $data = '{"test": "data"}';
        $prettyJson = Json::stringify(Json::parse($data), JSON_PRETTY_PRINT);

        // Set up all mocks BEFORE creating listener
        $this->request->method('get')->with('__inspector')->willReturn("$key.txt");
        $this->storage->method('read')->with($key)->willReturn($data);
        $this->response->method('text')
            ->willReturn($this->response);

        // Create listener after mocks are configured
        $listener = $this->makeListener();

        // Act & Assert
        $event = new RequestBegin($this->request);
        $this->expectException(StopFlow::class);
        $this->expectExceptionMessage('Inspector UI displayed');
        $listener->onRequestBegin($event);
    }

    public function testOnRequestBeginRendersRawWhenDataExists(): void
    {
        // Arrange
        $key = 'test-key-123';
        $data = '{"test": "data"}';

        // Set up all mocks BEFORE creating listener
        $this->request->method('get')->with('__inspector')->willReturn("$key.raw");
        $this->storage->method('read')->with($key)->willReturn($data);
        $this->response->method('text')
            ->willReturn($this->response);

        // Create listener after mocks are configured
        $listener = $this->makeListener();

        // Act & Assert
        $event = new RequestBegin($this->request);
        $this->expectException(StopFlow::class);
        $this->expectExceptionMessage('Inspector UI displayed');
        $listener->onRequestBegin($event);
    }

    public function testOnRequestBeginReturns404WhenDataNotFound(): void
    {
        // Arrange
        $key = 'non-existent-key';

        // Set up all mocks BEFORE creating listener
        $this->request->method('get')->with('__inspector')->willReturn("$key.html");
        $this->storage->method('read')->with($key)->willReturn(null);
        $this->response->method('text')
            ->willReturn($this->response);

        // Create listener after mocks are configured
        $listener = $this->makeListener();

        // Act & Assert
        $event = new RequestBegin($this->request);
        $this->expectException(StopFlow::class);
        $this->expectExceptionMessage('Inspector data not found');
        $listener->onRequestBegin($event);
    }

    public function testOnRequestBeginHandlesComplexKeyPath(): void
    {
        // Arrange
        $listener = $this->makeListener();
        $key = '/240207/120000_abc123def456';
        $data = '{"test": "data"}';
        $html = '<html>Inspector UI</html>';

        $this->request->method('get')->with('__inspector')->willReturn("$key.html");
        $this->storage->method('read')->with($key)->willReturn($data);
        $this->htmlRenderer->method('render')->with($data)->willReturn($html);
        $this->response->method('text')
            ->with($html, 'text/html')
            ->willReturn($this->response);

        // Act & Assert
        $event = new RequestBegin($this->request);
        $this->expectException(StopFlow::class);
        $this->expectExceptionMessage('Inspector UI displayed');
        $listener->onRequestBegin($event);
    }
}
