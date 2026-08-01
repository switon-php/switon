<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Mcp\ServiceProvider;
use Switon\Mcp\Tests\TestCase;
use Switon\Mcp\ToolDiscoveryInterface;

#[CoversClass(ServiceProvider::class)]
class ServiceProviderTest extends TestCase
{
    public function testRegisterIsNoOp(): void
    {
        $provider = new ServiceProvider();
        $provider->register($this->container);

        $this->assertTrue(true);
    }

    public function testBootRunsToolDiscovery(): void
    {
        $discovery = $this->createMock(ToolDiscoveryInterface::class);
        $discovery->expects($this->once())->method('discover');

        $provider = $this->make(ServiceProvider::class, ['toolDiscovery' => $discovery]);
        $provider->boot();
    }
}
