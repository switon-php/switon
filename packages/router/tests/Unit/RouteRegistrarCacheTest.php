<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\ClassScannerInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Switon\Core\PathAliasInterface;
use Switon\Routing\MappingPath;
use Switon\Routing\MappingPathInterface;
use Switon\Routing\Router;
use Switon\Routing\RouteRegistrar;
use Switon\Routing\RouterInterface;
use Switon\Routing\Tests\Fixtures\TestUserController;
use Switon\Testing\Container;
use Switon\Testing\TestCase as BaseTestCase;

#[AllowMockObjectsWithoutExpectations]
class RouteRegistrarCacheTest extends BaseTestCase
{
    public function testSecondProcessUsesManifestAndSkipsClassScanner(): void
    {
        $runtime = sys_get_temp_dir() . '/switon_router_manifest_' . uniqid('', true);
        mkdir($runtime, 0755, true);

        $classScanner1 = $this->createMock(ClassScannerInterface::class);
        $classScanner1->expects($this->once())
            ->method('scan')
            ->willReturn([TestUserController::class]);

        $c1 = new Container();
        $c1->get(PathAliasInterface::class)->set('@runtime', $runtime);
        $c1->set(ClassScannerInterface::class, $classScanner1);
        $router1 = $c1->make(Router::class);
        $c1->set(RouterInterface::class, $router1);
        $c1->set(MappingPathInterface::class, $c1->make(MappingPath::class));

        $registrar1 = $c1->make(RouteRegistrar::class, [
            'cache' => true,
            'file' => '@runtime/routes_manifest.json',
            'files' => [TestUserController::class],
        ]);
        $registrar1->register();

        $fs = $c1->get(FilesystemInterface::class);
        $this->assertTrue($fs->exists('@runtime/routes_manifest.json'));

        $matcher = $router1->match('/api/user/index', 'GET');
        $this->assertStringContainsString('indexAction', $matcher->getHandler());

        $classScanner2 = $this->createMock(ClassScannerInterface::class);
        $classScanner2->expects($this->never())->method('scan');

        $c2 = new Container();
        $c2->get(PathAliasInterface::class)->set('@runtime', $runtime);
        $c2->set(ClassScannerInterface::class, $classScanner2);
        $router2 = $c2->make(Router::class);
        $c2->set(RouterInterface::class, $router2);
        $c2->set(MappingPathInterface::class, $c2->make(MappingPath::class));

        $registrar2 = $c2->make(RouteRegistrar::class, [
            'cache' => true,
            'file' => '@runtime/routes_manifest.json',
            'files' => [TestUserController::class],
        ]);
        $registrar2->register();

        $matcher2 = $router2->match('/api/user/index', 'GET');
        $this->assertSame($matcher->getHandler(), $matcher2->getHandler());
    }

    public function testPrebuiltManifestSkipsClassScanner(): void
    {
        $runtime = sys_get_temp_dir() . '/switon_router_manifest_prebuilt_' . uniqid('', true);
        mkdir($runtime, 0755, true);

        $handler = TestUserController::class . '::indexAction';
        $manifest = Json::stringify([
            'version' => 1,
            'routes' => [
                ['verb' => 'GET', 'pattern' => '/api/user/index', 'handler' => $handler],
            ],
        ]);

        $c = new Container();
        $c->get(PathAliasInterface::class)->set('@runtime', $runtime);
        $c->get(FilesystemInterface::class)->write('@runtime/routes_only.json', $manifest);

        $classScanner = $this->createMock(ClassScannerInterface::class);
        $classScanner->expects($this->never())->method('scan');
        $c->set(ClassScannerInterface::class, $classScanner);

        $router = $c->make(Router::class);
        $c->set(RouterInterface::class, $router);
        $c->set(MappingPathInterface::class, $c->make(MappingPath::class));

        $registrar = $c->make(RouteRegistrar::class, [
            'cache' => true,
            'file' => '@runtime/routes_only.json',
            'files' => [TestUserController::class],
        ]);
        $registrar->register();

        $matcher = $router->match('/api/user/index', 'GET');
        $this->assertSame($handler, $matcher->getHandler());
    }
}
