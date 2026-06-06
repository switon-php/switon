<?php

declare(strict_types=1);

namespace Switon\Context\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Context\ContextManagerInterface;
use Switon\Context\ServiceProvider;
use Switon\Context\Tests\TestCase;
use Switon\Core\ContainerInterface;
use Switon\Core\ContextManagerInterface as CoreContextManagerInterface;

#[AllowMockObjectsWithoutExpectations]
class ServiceProviderTest extends TestCase
{
    public function testResolvedManagerImplementsPackageAndCoreInterfaces(): void
    {
        $manager = $this->container->get(ContextManagerInterface::class);

        $this->assertInstanceOf(ContextManagerInterface::class, $manager);
        $this->assertInstanceOf(CoreContextManagerInterface::class, $manager);
    }

    public function testRegisterBindsCoreContextManagerToPackageInterface(): void
    {
        $provider = new ServiceProvider();
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('set')
            ->with(CoreContextManagerInterface::class, ContextManagerInterface::class)
            ->willReturnSelf();

        $provider->register($container);
    }

    public function testBootIsNoopAndDoesNotThrow(): void
    {
        $provider = new ServiceProvider();

        $provider->boot();

        $this->addToAssertionCount(1);
    }
}
