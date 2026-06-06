<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit\Collectors;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\Attribute\Autowired;
use Switon\Http\RequestInterface;
use Switon\Inspector\BasicCollector;
use Switon\Inspector\Tests\TestCase;
use Switon\Kernel\VersionInterface;

#[AllowMockObjectsWithoutExpectations]
class BasicCollectorTest extends TestCase
{
    #[Autowired] protected BasicCollector $collector;
    #[Autowired] protected RequestInterface $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(RequestInterface::class);
        $this->container->set(RequestInterface::class, $this->request);
        $this->container->set(VersionInterface::class, new class () implements VersionInterface {
            public function version(): string
            {
                return '3.4.2';
            }
        });

        $this->collector = $this->container->make(BasicCollector::class);
        $this->injector->inject($this);
    }

    public function testCollectReturnsRequestSection(): void
    {
        // Arrange
        $this->request->method('handler')
            ->willReturn('App\\Controller\\HomeController::index');
        $this->request->method('verb')->willReturn('POST');
        $this->request->method('url')->willReturn('/api/users');
        $this->request->method('server')
            ->willReturnCallback(fn ($key) => match ($key) {
                'QUERY_STRING' => 'page=1&limit=10',
                default => '',
            });
        $this->request->method('ip')->willReturn('127.0.0.1');
        $this->request->method('elapsed')->willReturn(0.1);

        // Act
        $result = $this->collector->collect();

        // Assert
        $this->assertArrayHasKey('request', $result);
        $this->assertSame('App\\Controller\\HomeController::index', $result['request']['handler']);
        $this->assertSame('POST', $result['request']['method']);
        $this->assertSame('/api/users', $result['request']['url']);
        $this->assertSame('page=1&limit=10', $result['request']['query']);
    }

    public function testCollectReturnsPerformanceSection(): void
    {
        // Arrange
        $this->request->method('handler')->willReturn('');
        $this->request->method('verb')->willReturn('GET');
        $this->request->method('url')->willReturn('/');
        $this->request->method('server')->willReturn('');
        $this->request->method('ip')->willReturn('127.0.0.1');
        $this->request->method('elapsed')->willReturn(0.025);

        // Act
        $result = $this->collector->collect();

        // Assert
        $this->assertArrayHasKey('performance', $result);
        $this->assertSame(0.025, $result['performance']['execute_time']);
        $this->assertStringContainsString('k/', $result['performance']['memory_usage']);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $result['performance']['system_time']);
    }

    public function testCollectReturnsServerSection(): void
    {
        // Arrange
        $this->request->method('handler')->willReturn('');
        $this->request->method('verb')->willReturn('GET');
        $this->request->method('url')->willReturn('/');
        $this->request->method('server')
            ->willReturnCallback(fn ($key) => match ($key) {
                'SERVER_ADDR' => '192.168.1.1',
                'SERVER_SOFTWARE' => 'swoole-http-server',
                default => '',
            });
        $this->request->method('ip')->willReturn('10.0.0.1');
        $this->request->method('elapsed')->willReturn(0.0);

        // Act
        $result = $this->collector->collect();

        // Assert
        $this->assertArrayHasKey('server', $result);
        $this->assertSame('192.168.1.1', $result['server']['ip']);
        $this->assertSame('10.0.0.1', $result['server']['client_ip']);
        $this->assertSame('swoole-http-server', $result['server']['software']);
    }

    public function testCollectReturnsPhpSection(): void
    {
        // Arrange
        $this->request->method('handler')->willReturn('');
        $this->request->method('verb')->willReturn('GET');
        $this->request->method('url')->willReturn('/');
        $this->request->method('server')->willReturn('');
        $this->request->method('ip')->willReturn('127.0.0.1');
        $this->request->method('elapsed')->willReturn(0.0);

        // Act
        $result = $this->collector->collect();

        // Assert
        $this->assertArrayHasKey('php', $result);
        $this->assertSame('3.4.2', $result['php']['framework_version']);
        $this->assertSame('1.0.0', $result['php']['app_version']);
        $this->assertSame(PHP_VERSION, $result['php']['version']);
        $this->assertSame(PHP_SAPI, $result['php']['sapi']);
        $this->assertArrayHasKey('loaded_ini', $result['php']);
        $this->assertArrayHasKey('loaded_extensions', $result['php']);
        $this->assertArrayHasKey('opcache_enable', $result['php']);
        $this->assertArrayHasKey('opcache_enable_cli', $result['php']);
    }

    public function testJsonSerializeReturnsEmptyArray(): void
    {
        // Act
        $result = $this->collector->jsonSerialize();

        // Assert
        $this->assertSame([], $result);
    }

    public function testBootIsNoOp(): void
    {
        $this->expectNotToPerformAssertions();

        $this->collector->boot();
    }
}
