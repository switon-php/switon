<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Http\Tests\Fixtures\ETagActionFixture;
use Switon\Http\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ETagTest extends TestCase
{
    protected RequestInterface $request;
    protected ResponseInterface $response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = $this->createMock(RequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->container->set(RequestInterface::class, $this->request);
        $this->container->set(ResponseInterface::class, $this->response);
    }

    public function testETagWithFieldValue(): void
    {
        $content = json_encode(['id' => 1, 'updated_at' => '2024-01-19']);

        $this->response->method('getContent')->willReturn($content);
        $this->request->method('header')->with('if-none-match')->willReturn(null);

        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('ETag', '"2024-01-19"');

        $this->invokeAction(new ETagActionFixture(), 'withField');
    }

    public function testETagWithoutField(): void
    {
        $content = json_encode(['data' => 'test']);
        $expectedEtag = md5($content);

        $this->response->method('getContent')->willReturn($content);
        $this->request->method('header')->with('if-none-match')->willReturn(null);

        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('ETag', "\"{$expectedEtag}\"");

        $this->invokeAction(new ETagActionFixture(), 'withoutField');
    }

    public function testETagWith304Response(): void
    {
        $content = json_encode(['id' => 1, 'updated_at' => '2024-01-19']);

        $this->response->method('getContent')->willReturn($content);
        $this->request->method('header')
            ->with('if-none-match')
            ->willReturn('"2024-01-19"');

        $this->response->expects($this->once())
            ->method('setStatus')
            ->with(304, 'Not Modified');

        $this->response->expects($this->once())
            ->method('setContent')
            ->with('');

        $this->invokeAction(new ETagActionFixture(), 'with304Response');
    }

    public function testETagWithEmptyContent(): void
    {
        $this->response->method('getContent')->willReturn('');
        $this->response->expects($this->never())->method('setHeader');

        $this->invokeAction(new ETagActionFixture(), 'withEmptyContent');
    }

    public function testETagFieldNotFoundFallbackToHash(): void
    {
        $content = json_encode(['id' => 1, 'name' => 'John']);
        $expectedEtag = md5($content);

        $this->response->method('getContent')->willReturn($content);
        $this->request->method('header')->with('if-none-match')->willReturn(null);

        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('ETag', "\"{$expectedEtag}\"");

        $this->invokeAction(new ETagActionFixture(), 'fieldNotFoundFallbackToHash');
    }

    public function testETagWithNonJsonContent(): void
    {
        $content = 'plain text content';
        $expectedEtag = md5($content);

        $this->response->method('getContent')->willReturn($content);
        $this->request->method('header')->with('if-none-match')->willReturn(null);

        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('ETag', "\"{$expectedEtag}\"");

        $this->invokeAction(new ETagActionFixture(), 'withNonJsonContent');
    }

    public function testInvokeDoesNotBlockWhenInterceptorAllowsRequest(): void
    {
        $this->response->method('getContent')->willReturn(json_encode(['data' => 'x']));

        $result = $this->invokeAction(new ETagActionFixture(), 'withoutField');
        $this->assertIsArray($result);
    }
}
