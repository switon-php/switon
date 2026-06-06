<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit;

use Switon\Core\ContainerInterface;
use Switon\Core\PathAliasInterface;
use Switon\Inspector\InspectorInterface;
use Switon\Inspector\ServiceProvider;
use Switon\Inspector\Tests\TestCase;
use Switon\Testing\PackagePathAssert;

class ServiceProviderTest extends TestCase
{
    public function testRegisterIsNoopAndDoesNotTouchContainer(): void
    {
        $provider = new ServiceProvider();
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('set');
        $container->expects($this->never())->method('get');
        $container->expects($this->never())->method('has');

        $provider->register($container);

        $this->addToAssertionCount(1);
    }

    public function testBootCallsInspectorBoot(): void
    {
        $inspector = $this->createMock(InspectorInterface::class);
        $inspector->expects($this->once())->method('boot');
        $this->container->set(InspectorInterface::class, $inspector);

        $provider = $this->container->make(ServiceProvider::class);
        $pathAlias = $this->container->get(PathAliasInterface::class);
        $resourceRoot = $pathAlias->get('@switon.inspector.resources');
        $this->assertIsString($resourceRoot);
        PackagePathAssert::assertSameAsPackagePath(ServiceProvider::class, $resourceRoot, 'resources');

        $this->injector->inject($provider);
        $provider->boot();

        $this->assertSame($resourceRoot, $pathAlias->get('@switon.inspector.resources'));
    }
}
