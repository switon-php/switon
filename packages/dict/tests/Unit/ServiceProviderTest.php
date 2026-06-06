<?php

declare(strict_types=1);

namespace Switon\Dict\Tests\Unit;

use Switon\Core\ContainerInterface;
use Switon\Core\PathAliasInterface;
use Switon\Dict\ServiceProvider;
use Switon\Testing\PackagePathAssert;
use Switon\Testing\TestCase;
use Switon\Validating\ValidatorInterface;

class ServiceProviderTest extends TestCase
{
    public function testRegisterAddsDictTemplatesToValidatorDirs(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('set')
            ->with(ValidatorInterface::class, [
                'dirs' => ['@switon.dict.resources'],
            ]);

        $provider = new ServiceProvider();
        $provider->register($container);
    }

    public function testContainerRegistersDictResourceAliasFromProviderAttribute(): void
    {
        $pathAlias = $this->container->get(PathAliasInterface::class);
        $resourceRoot = $pathAlias->get('@switon.dict.resources');
        $this->assertIsString($resourceRoot);
        PackagePathAssert::assertSameAsPackagePath(ServiceProvider::class, $resourceRoot, 'src/Templates');
    }

    public function testBootIsNoop(): void
    {
        $provider = new ServiceProvider();

        $provider->boot();

        $this->addToAssertionCount(1);
    }

    public function testTemplatesExposeDictValueMessages(): void
    {
        $english = require dirname(__DIR__, 2) . '/src/Templates/en.php';
        $chinese = require dirname(__DIR__, 2) . '/src/Templates/zh-cn.php';

        $this->assertSame('The {field} must be a valid dict value.', $english[\Switon\Dict\Attribute\DictValue::class]);
        $this->assertSame('{field} 不是有效的字典值', $chinese[\Switon\Dict\Attribute\DictValue::class]);
    }
}
