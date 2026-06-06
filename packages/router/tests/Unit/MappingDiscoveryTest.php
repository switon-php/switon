<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Switon\Core\ClassScannerInterface;
use Switon\Routing\MappingDiscovery;
use Switon\Routing\MappingDiscoveryInterface;
use Switon\Routing\Tests\Fixtures\TestUserController;
use Switon\Testing\Container;
use Switon\Testing\TestCase as BaseTestCase;

#[AllowMockObjectsWithoutExpectations]
class MappingDiscoveryTest extends BaseTestCase
{
    protected Container $container;
    protected ClassScannerInterface&MockObject $classScanner;
    protected MappingDiscoveryInterface $discovery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        $this->classScanner = $this->createMock(ClassScannerInterface::class);
        $this->container->set(ClassScannerInterface::class, $this->classScanner);

        $this->discovery = $this->container->make(MappingDiscovery::class);
    }

    public function testDiscoverClassReturnsResolvedRoutesForValidController(): void
    {
        $routes = $this->discovery->discoverClass(TestUserController::class);

        $this->assertCount(3, $routes);
        $this->assertContains(
            ['verb' => 'GET', 'pattern' => '/api/user/index', 'handler' => TestUserController::class . '::indexAction'],
            $routes
        );
        $this->assertContains(
            ['verb' => 'GET', 'pattern' => '/{id}', 'handler' => TestUserController::class . '::showAction'],
            $routes
        );
        $this->assertContains(
            ['verb' => 'POST', 'pattern' => '/api/user', 'handler' => TestUserController::class . '::createAction'],
            $routes
        );
    }

    public function testDiscoverClassReturnsEmptyArrayForClassWithoutRequestMapping(): void
    {
        $routes = $this->discovery->discoverClass(\Switon\Routing\Tests\Fixtures\TestUnmappedController::class);

        $this->assertSame([], $routes);
    }

    public function testDiscoverUsesClassScannerAndAggregatesRoutes(): void
    {
        $this->classScanner->expects($this->once())
            ->method('scan')
            ->with(
                ['@app/Controller/*Controller.php' => 'App\\Controller\\*Controller'],
                \Switon\Routing\Attribute\RequestMapping::class
            )
            ->willReturn([TestUserController::class]);

        $routes = $this->discovery->discover(['@app/Controller/*Controller.php' => 'App\\Controller\\*Controller']);

        $this->assertCount(3, $routes);
    }
}
