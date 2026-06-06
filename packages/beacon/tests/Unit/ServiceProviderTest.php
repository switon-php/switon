<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use Switon\Beacon\Listener\AiToolRawArgvLogger;
use Switon\Beacon\ServiceProvider;
use Switon\Core\ContainerInterface;
use Switon\Core\PathAliasInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Testing\PackagePathAssert;
use Switon\Beacon\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function testRegisterDoesNothing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('set');

        $provider = new ServiceProvider();
        $provider->register($container);

        $this->assertTrue(true);
    }

    public function testBootRegistersAiToolRawArgvLoggerAndBeaconResourceAlias(): void
    {
        $listenerProvider = $this->createMock(ListenerProviderInterface::class);
        $listenerProvider->expects($this->once())
            ->method('register')
            ->with(AiToolRawArgvLogger::class);

        $this->container->replace(ListenerProviderInterface::class, $listenerProvider);

        /** @var ServiceProvider $provider */
        $provider = $this->make(ServiceProvider::class);

        $pathAlias = $this->container->get(PathAliasInterface::class);
        $resourceRoot = $pathAlias->get('@switon.beacon.resources');
        $this->assertIsString($resourceRoot);
        PackagePathAssert::assertSameAsPackagePath(ServiceProvider::class, $resourceRoot, 'resources');

        $provider->boot();

        $this->assertSame($resourceRoot, $pathAlias->get('@switon.beacon.resources'));
    }
}
