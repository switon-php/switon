<?php

declare(strict_types=1);

namespace Switon\Schedule\Tests\Unit;

use Switon\Schedule\ServiceProvider;
use Switon\Schedule\TaskDiscoveryInterface;
use PHPUnit\Framework\TestCase;

class ServiceProviderTest extends TestCase
{
    public function testBootRunsTaskDiscovery(): void
    {
        $discovery = $this->createMock(TaskDiscoveryInterface::class);
        $discovery->expects($this->once())->method('discover');

        $provider = new TestableServiceProvider();
        $provider->setTaskDiscovery($discovery);

        $provider->boot();
    }
}

class TestableServiceProvider extends ServiceProvider
{
    public function setTaskDiscovery(TaskDiscoveryInterface $taskDiscovery): void
    {
        $this->taskDiscovery = $taskDiscovery;
    }
}
