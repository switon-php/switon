<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Switon\Core\ClassScannerInterface;
use Switon\Routing\RouteRegistrar;
use Switon\Routing\RouteRegistrarInterface;
use Switon\Routing\RouterInterface;
use Switon\Routing\Tests\Fixtures\TestUserController;
use Switon\Testing\Container;
use Switon\Testing\TestCase as BaseTestCase;

#[AllowMockObjectsWithoutExpectations]
class RouteRegistrarTest extends BaseTestCase
{
    protected Container $container;
    protected RouterInterface&MockObject $routerMock;
    protected ClassScannerInterface&MockObject $classScanner;
    protected RouteRegistrarInterface $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();

        $this->routerMock = $this->createMock(RouterInterface::class);
        $this->container->set(RouterInterface::class, $this->routerMock);

        $this->classScanner = $this->createMock(ClassScannerInterface::class);
        $this->container->set(ClassScannerInterface::class, $this->classScanner);

        $this->registrar = $this->container->make(RouteRegistrar::class);
    }

    public function testRegisterClassRegistersRoutesForValidController(): void
    {
        $calls = [];
        $this->routerMock->expects($this->atLeastOnce())
            ->method('add')
            ->willReturnCallback(function ($method, $pattern, $handler) use (&$calls) {
                $calls[] = [$method, $pattern, $handler];
            });

        $this->registrar->registerClass(TestUserController::class);

        $this->assertCount(3, $calls);

        // Verify all routes are registered (order may vary)
        $patterns = array_column($calls, 1);
        $this->assertContains('/api/user/index', $patterns, 'Should register index route');
        // Note: Path '/{id}' with prefix '/api/user' becomes '/{id}' (absolute path takes precedence)
        $this->assertContains('/{id}', $patterns, 'Should register show route with /{id}');
        $this->assertContains('/api/user', $patterns, 'Should register create route');

        // Verify handlers
        $handlers = array_column($calls, 2);
        $this->assertContains(TestUserController::class . '::indexAction', $handlers);
        $this->assertContains(TestUserController::class . '::showAction', $handlers);
        $this->assertContains(TestUserController::class . '::createAction', $handlers);
    }

    public function testRegisterClassDoesNothingForNonExistentClass(): void
    {
        $this->routerMock->expects($this->never())
            ->method('add');

        $this->registrar->registerClass('NonExistent\\Controller');
    }

    public function testRegisterClassDoesNothingForClassWithoutRequestMapping(): void
    {
        $this->routerMock->expects($this->never())
            ->method('add');

        // TestUnmappedController has GetMapping but no RequestMapping
        $this->registrar->registerClass(\Switon\Routing\Tests\Fixtures\TestUnmappedController::class);
    }

    public function testRegisterClassHandlesEmptyRequestMapping(): void
    {
        $this->routerMock->expects($this->once())
            ->method('add')
            ->with('GET', '/index', \Switon\Routing\Tests\Fixtures\TestIndexController::class . '::indexAction');

        $this->registrar->registerClass(\Switon\Routing\Tests\Fixtures\TestIndexController::class);
    }

    public function testRegisterClassHandlesNullPathMapping(): void
    {
        // TestUserController has GetMapping() with null path, which should auto-generate path
        $calls = [];
        $this->routerMock->expects($this->atLeastOnce())
            ->method('add')
            ->willReturnCallback(function ($method, $pattern, $handler) use (&$calls) {
                $calls[] = [$method, $pattern, $handler];
            });

        $this->registrar->registerClass(TestUserController::class);

        // Null path should auto-generate from method name
        $patterns = array_column($calls, 1);
        $this->assertContains('/api/user/index', $patterns);
    }

    public function testRegisterClassHandlesViewableMapping(): void
    {
        $calls = [];
        $this->routerMock->expects($this->atLeastOnce())
            ->method('add')
            ->willReturnCallback(function ($method, $pattern, $handler) use (&$calls) {
                $calls[] = [$method, $pattern, $handler];
            });

        $this->registrar->registerClass(\Switon\Routing\Tests\Fixtures\TestViewController::class);

        // ViewGetMapping should register only one GET route.
        $methods = array_column($calls, 0);
        $patterns = array_column($calls, 1);

        // Should have exactly one GET route for the view mapping.
        $this->assertSame(1, count($methods));
        $this->assertSame(['GET'], $methods);
        $this->assertContains('/view/test', $patterns);
        $this->assertSame('/view/test', $patterns[0]);
    }

    public function testRegisterClassHandlesRequestMappingWithNullPath(): void
    {
        // TestIndexController has RequestMapping(''), prefix should be empty
        $calls = [];
        $this->routerMock->expects($this->once())
            ->method('add')
            ->willReturnCallback(function ($method, $pattern, $handler) use (&$calls) {
                $calls[] = [$method, $pattern, $handler];
            });

        $this->registrar->registerClass(\Switon\Routing\Tests\Fixtures\TestIndexController::class);

        // Empty RequestMapping should result in '/index' (null path auto-generates from method name)
        $this->assertSame('GET', $calls[0][0]);
        $this->assertSame('/index', $calls[0][1]);
    }

    public function testRegisterClassHandlesArrayPrefixAtClassLevel(): void
    {
        // Arrange
        $calls = [];
        $this->routerMock->expects($this->atLeastOnce())
            ->method('add')
            ->willReturnCallback(function ($method, $pattern, $handler) use (&$calls) {
                $calls[] = [$method, $pattern, $handler];
            });

        // Act
        $this->registrar->registerClass(\Switon\Routing\Tests\Fixtures\TestMultiplePrefixController::class);

        // Assert - each action should be registered under both prefixes
        $patterns = array_column($calls, 1);

        // indexAction: auto-generated path under both prefixes
        $this->assertContains('/api/v1/user/index', $patterns, 'Should register index under v1');
        $this->assertContains('/api/v2/user/index', $patterns, 'Should register index under v2');

        // showAction: relative path {id} under both prefixes
        $this->assertContains('/api/v1/user/{id}', $patterns, 'Should register show under v1');
        $this->assertContains('/api/v2/user/{id}', $patterns, 'Should register show under v2');

        // createAction: empty path under both prefixes
        $this->assertContains('/api/v1/user', $patterns, 'Should register create under v1');
        $this->assertContains('/api/v2/user', $patterns, 'Should register create under v2');

        $this->assertCount(6, $calls, 'Should register 3 actions × 2 prefixes = 6 routes');
    }

    public function testRegisterClassHandlesArrayPathAtMethodLevel(): void
    {
        // Arrange
        $calls = [];
        $this->routerMock->expects($this->atLeastOnce())
            ->method('add')
            ->willReturnCallback(function ($method, $pattern, $handler) use (&$calls) {
                $calls[] = [$method, $pattern, $handler];
            });

        // Act
        $this->registrar->registerClass(\Switon\Routing\Tests\Fixtures\TestMultiplePathController::class);

        // Assert
        $patterns = array_column($calls, 1);
        $this->assertContains('/api/product/list', $patterns, 'Should register list path');
        $this->assertContains('/api/product/all', $patterns, 'Should register all path');
        $this->assertCount(2, $calls, 'Should register 1 action × 2 paths = 2 routes');
    }

    public function testRegisterUsesClassScannerForDiscovery(): void
    {
        $this->classScanner->expects($this->once())
            ->method('scan')
            ->with(
                $this->anything(),
                \Switon\Routing\Attribute\RequestMapping::class
            )
            ->willReturn([TestUserController::class]);

        $calls = [];
        $this->routerMock->expects($this->atLeastOnce())
            ->method('add')
            ->willReturnCallback(function ($method, $pattern, $handler) use (&$calls) {
                $calls[] = [$method, $pattern, $handler];
            });

        $this->registrar->register();

        $this->assertCount(3, $calls);
    }

    public function testRegisterClassAutoGeneratesPrefixFromClassName(): void
    {
        // Arrange
        $calls = [];
        $this->routerMock->expects($this->atLeastOnce())
            ->method('add')
            ->willReturnCallback(function ($method, $pattern, $handler) use (&$calls) {
                $calls[] = [$method, $pattern, $handler];
            });

        // Act - TestAutoNamingController has #[RequestMapping] with no path argument
        $this->registrar->registerClass(\Switon\Routing\Tests\Fixtures\TestAutoNamingController::class);

        // Assert - prefix auto-generated from class name: TestAutoNaming → /test-auto-naming
        $patterns = array_column($calls, 1);
        $this->assertContains('/test-auto-naming/index', $patterns, 'Should auto-generate prefix from class name');
        $this->assertContains('/test-auto-naming/{id}', $patterns, 'Should combine auto prefix with relative path');
        $this->assertCount(2, $calls);
    }

    public function testRegisterClassHandlesArrayPrefixCombinedWithArrayPath(): void
    {
        // Arrange
        $calls = [];
        $this->routerMock->expects($this->atLeastOnce())
            ->method('add')
            ->willReturnCallback(function ($method, $pattern, $handler) use (&$calls) {
                $calls[] = [$method, $pattern, $handler];
            });

        // Act
        $this->registrar->registerClass(\Switon\Routing\Tests\Fixtures\TestMultipleBothController::class);

        // Assert - 2 prefixes × 2 paths = 4 routes
        $patterns = array_column($calls, 1);
        $this->assertContains('/api/v1/order/list', $patterns);
        $this->assertContains('/api/v1/order/all', $patterns);
        $this->assertContains('/api/v2/order/list', $patterns);
        $this->assertContains('/api/v2/order/all', $patterns);
        $this->assertCount(4, $calls, 'Should register 2 prefixes × 2 paths = 4 routes');
    }
}
