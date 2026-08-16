<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Tooling\ServiceProvider;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\ToolDiscoveryInterface;

#[CoversClass(ServiceProvider::class)]
class ServiceProviderTest extends ToolTestCase
{
    public function testBootRunsToolDiscovery(): void
    {
        $discovery = $this->createMock(ToolDiscoveryInterface::class);
        $discovery->expects($this->once())->method('discover');

        $provider = $this->make(ServiceProvider::class, [
            'toolDiscovery' => $discovery,
        ]);
        $provider->boot();
    }

    public function testRegisterDoesNotDiscoverTools(): void
    {
        $discovery = $this->createMock(ToolDiscoveryInterface::class);
        $discovery->expects($this->never())->method('discover');

        $provider = $this->make(ServiceProvider::class, [
            'toolDiscovery' => $discovery,
        ]);
        $provider->register($this->container);
    }
}
