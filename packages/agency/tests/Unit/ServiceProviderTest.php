<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use Switon\Agency\ServiceProvider;
use Switon\Agency\Tests\CatalogTestCase;
use Switon\Core\PathAliasInterface;
use Switon\Testing\PackagePathAssert;

class ServiceProviderTest extends CatalogTestCase
{
    public function testContainerRegistersAgentsResourceAlias(): void
    {
        $pathAlias = $this->container->get(PathAliasInterface::class);
        $catalogRoot = $pathAlias->resolve('@agency/agents');
        PackagePathAssert::assertSameAsPackagePath(ServiceProvider::class, $catalogRoot, 'resources/agents');
        $this->assertFileExists($pathAlias->resolve('@agency/agents/instructor.md'));
    }
}
