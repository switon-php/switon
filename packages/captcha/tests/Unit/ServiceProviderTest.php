<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Captcha\ServiceProvider;
use Switon\Core\ContainerInterface;
use Switon\Core\PathAliasInterface;
use Switon\Testing\Container;
use Switon\Testing\PackagePathAssert;

class ServiceProviderTest extends TestCase
{
    public function testRegisterIsNoop(): void
    {
        $provider = new ServiceProvider();
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('set');

        $provider->register($container);

        $this->addToAssertionCount(1);
    }

    public function testBootIsNoop(): void
    {
        $provider = new ServiceProvider();

        $provider->boot();

        $this->addToAssertionCount(1);
    }

    public function testContainerRegistersCaptchaResourceAliasFromProviderAttribute(): void
    {
        $container = new Container();
        $pathAlias = $container->get(PathAliasInterface::class);
        $resourceRoot = $pathAlias->get('@switon.captcha.resources');
        $this->assertIsString($resourceRoot);
        PackagePathAssert::assertSameAsPackagePath(ServiceProvider::class, $resourceRoot, 'resources');
    }
}
