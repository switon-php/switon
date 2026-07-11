<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit\Transformers;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Event\RequestInvoked;
use Switon\Http\RequestHandlerInterface;
use Switon\Http\Response\JsonRendererInterface;
use Switon\Http\ResponseInterface;
use Switon\Http\ServerInterface;
use Switon\Http\Tests\Fixtures\NormalizeReturnActionFixture;
use Switon\Http\Tests\Support\RequestDispatchHarness;
use Switon\Http\Tests\TestCase;
use Switon\Http\Transformer\NormalizeActionReturnTransformer;
use Switon\Routing\RouterInterface;
use RuntimeException;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class NormalizeActionReturnTransformerTest extends TestCase
{
    #[Autowired] protected NormalizeActionReturnTransformer $transformer;
    #[Autowired] protected ResponseInterface $response;
    #[Autowired] protected JsonRendererInterface $jsonRenderer;
    #[Autowired] protected RequestHandlerInterface $requestHandler;

    private NormalizeReturnActionFixture $actions;

    protected function beforeSetUpHttpContainer(): void
    {
        $this->jsonRenderer = $this->createMock(JsonRendererInterface::class);
        $this->container->remove(JsonRendererInterface::class);
        $this->container->replace(JsonRendererInterface::class, $this->jsonRenderer);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actions = new NormalizeReturnActionFixture();
    }

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->container->replace(RouterInterface::class, $this->createStub(RouterInterface::class));
        $this->container->replace(ServerInterface::class, $this->createStub(ServerInterface::class));
    }

    private function invokedEvent(mixed $return): RequestInvoked
    {
        return RequestDispatchHarness::invokedEvent(
            $this->requestHandler,
            $this->actions,
            'sampleAction',
            $return,
        );
    }

    public function testOnInvokedNormalizesNullReturnValue(): void
    {
        $this->jsonRenderer->expects($this->once())
            ->method('render')
            ->with(['code' => 0, 'msg' => '']);

        $this->transformer->onInvoked($this->invokedEvent(null));
    }

    public function testOnInvokedNormalizesArrayReturnValue(): void
    {
        $data = ['key' => 'value'];
        $this->jsonRenderer->expects($this->once())
            ->method('render')
            ->with(['code' => 0, 'msg' => '', 'data' => $data]);

        $this->transformer->onInvoked($this->invokedEvent($data));
    }

    public function testOnInvokedNormalizesStringReturnValueAsError(): void
    {
        $errorMessage = 'Error occurred';
        $this->jsonRenderer->expects($this->once())
            ->method('render')
            ->with(['code' => -1, 'msg' => $errorMessage]);

        $this->transformer->onInvoked($this->invokedEvent($errorMessage));
    }

    public function testOnInvokedNormalizesIntReturnValue(): void
    {
        $code = 200;
        $this->jsonRenderer->expects($this->once())
            ->method('render')
            ->with(['code' => $code, 'msg' => '']);

        $this->transformer->onInvoked($this->invokedEvent($code));
    }

    public function testOnInvokedDoesNothingForResponseReturnValue(): void
    {
        $this->jsonRenderer->expects($this->never())
            ->method('render');

        $this->transformer->onInvoked($this->invokedEvent($this->response));
    }

    public function testOnInvokedThrowsThrowableReturnValue(): void
    {
        $exception = new RuntimeException('Test exception');

        $this->jsonRenderer->expects($this->never())
            ->method('render');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test exception');
        $this->transformer->onInvoked($this->invokedEvent($exception));
    }

    public function testOnInvokedNormalizesOtherReturnValue(): void
    {
        $object = new stdClass();
        $object->property = 'value';

        $this->jsonRenderer->expects($this->once())
            ->method('render')
            ->with(['code' => 0, 'msg' => '', 'data' => $object]);

        $this->transformer->onInvoked($this->invokedEvent($object));
    }

    public function testOnInvokedSkipsNormalizationWhenResponseHasContent(): void
    {
        $this->response->setContent('Already set');

        $this->jsonRenderer->expects($this->never())
            ->method('render');

        $this->transformer->onInvoked($this->invokedEvent(['key' => 'value']));
    }

    public function testOnInvokedSkipsNormalizationWhenResponseHasFile(): void
    {
        $this->response->setFile(__FILE__);

        $this->jsonRenderer->expects($this->never())
            ->method('render');

        $this->transformer->onInvoked($this->invokedEvent(null));
    }
}
