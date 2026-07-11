<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Http\ResponseInterface;
use Switon\Http\Tests\Fixtures\CacheControlActionFixture;
use Switon\Http\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CacheControlTest extends TestCase
{
    protected ResponseInterface $response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->response = $this->createMock(ResponseInterface::class);
        $this->container->set(ResponseInterface::class, $this->response);
    }

    public function testSimpleMaxAge(): void
    {
        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('Cache-Control', 'public, max-age=3600');

        $this->invokeAction(new CacheControlActionFixture(), 'simpleMaxAge');
    }

    public function testPrivateCache(): void
    {
        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('Cache-Control', 'private, max-age=1800');

        $this->invokeAction(new CacheControlActionFixture(), 'privateCache');
    }

    public function testWithSMaxAge(): void
    {
        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('Cache-Control', 'public, max-age=3600, s-maxage=7200');

        $this->invokeAction(new CacheControlActionFixture(), 'withSMaxAge');
    }

    public function testWithMustRevalidate(): void
    {
        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('Cache-Control', 'public, max-age=3600, must-revalidate');

        $this->invokeAction(new CacheControlActionFixture(), 'withMustRevalidate');
    }

    public function testNoCache(): void
    {
        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('Cache-Control', 'no-cache');

        $this->invokeAction(new CacheControlActionFixture(), 'noCache');
    }

    public function testNoStore(): void
    {
        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('Cache-Control', 'no-store, no-cache');

        $this->invokeAction(new CacheControlActionFixture(), 'noStore');
    }

    public function testNoStoreOverridesOtherSettings(): void
    {
        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('Cache-Control', 'no-store, no-cache');

        $this->invokeAction(new CacheControlActionFixture(), 'noStoreOverridesOtherSettings');
    }

    public function testComplexConfiguration(): void
    {
        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('Cache-Control', 'private, max-age=1800, s-maxage=3600, must-revalidate');

        $this->invokeAction(new CacheControlActionFixture(), 'complexConfiguration');
    }

    public function testOnlyPublicWithoutMaxAge(): void
    {
        $this->response->expects($this->once())
            ->method('setHeader')
            ->with('Cache-Control', 'public');

        $this->invokeAction(new CacheControlActionFixture(), 'onlyPublicWithoutMaxAge');
    }

    public function testInvokeDoesNotBlockWhenInterceptorAllowsRequest(): void
    {
        $result = $this->invokeAction(new CacheControlActionFixture(), 'onlyPublicWithoutMaxAge');
        $this->assertNull($result);
    }
}
