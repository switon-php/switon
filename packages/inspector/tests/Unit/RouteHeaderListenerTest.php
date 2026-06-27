<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\App;
use Switon\Core\AppInterface;
use Switon\Http\Event\HeadersSending;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Inspector\Listener\RouteHeaderListener;
use Switon\Inspector\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class RouteHeaderListenerTest extends TestCase
{
    protected RequestInterface $request;
    protected ResponseInterface $response;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(RequestInterface::class);
        $this->container->set(RequestInterface::class, $this->request);

        $this->response = $this->createMock(ResponseInterface::class);
        $this->container->set(ResponseInterface::class, $this->response);
    }

    protected function makeListener(string $env = 'dev', array $extra = []): RouteHeaderListener
    {
        $this->container->replace(AppInterface::class, ['class' => App::class, 'env' => $env, 'id' => 'test-app']);

        return $this->container->make(RouteHeaderListener::class, $extra);
    }

    public function testOnHeadersSendingSetsHeaderWhenEnabledInDev(): void
    {
        // Arrange
        $listener = $this->makeListener('dev');
        $handler = 'App\Controller\TestController::action';

        $this->request->expects($this->once())
            ->method('handler')
            ->willReturn($handler);

        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('X-Inspector-Route', $handler);

        // Act
        $event = new HeadersSending($this->createMock(\Switon\Http\ServerInterface::class), $this->response);
        $listener->onResponseHeadersSending($event);
    }

    public function testOnHeadersSendingDoesNotSetHeaderWhenDisabledInProduction(): void
    {
        // Arrange
        $listener = $this->makeListener('production');

        $this->request->expects($this->never())
            ->method('handler');

        $this->response->expects($this->never())
            ->method('setHeader');

        // Act
        $event = new HeadersSending($this->createMock(\Switon\Http\ServerInterface::class), $this->response);
        $listener->onResponseHeadersSending($event);
    }

    public function testOnHeadersSendingDoesNotSetHeaderWhenHandlerIsEmpty(): void
    {
        // Arrange
        $listener = $this->makeListener('dev');

        $this->request->expects($this->once())
            ->method('handler')
            ->willReturn('');

        $this->response->expects($this->never())
            ->method('setHeader');

        // Act
        $event = new HeadersSending($this->createMock(\Switon\Http\ServerInterface::class), $this->response);
        $listener->onResponseHeadersSending($event);
    }

    public function testOnHeadersSendingRespectsExplicitEnabledTrue(): void
    {
        // Arrange - env=production would normally disable, but explicit true overrides
        $listener = $this->makeListener('production', ['enabled' => true]);
        $handler = 'App\Controller\TestController::action';

        $this->request->expects($this->once())
            ->method('handler')
            ->willReturn($handler);

        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('X-Inspector-Route', $handler);

        // Act
        $event = new HeadersSending($this->createMock(\Switon\Http\ServerInterface::class), $this->response);
        $listener->onResponseHeadersSending($event);
    }

    public function testOnHeadersSendingRespectsExplicitEnabledFalse(): void
    {
        // Arrange - env=dev would normally enable, but explicit false overrides
        $listener = $this->makeListener('dev', ['enabled' => false]);

        $this->request->expects($this->never())
            ->method('handler');

        $this->response->expects($this->never())
            ->method('setHeader');

        // Act
        $event = new HeadersSending($this->createMock(\Switon\Http\ServerInterface::class), $this->response);
        $listener->onResponseHeadersSending($event);
    }
}
