<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\HeadlessChrome;
use Switon\Cdp\ServiceProvider;
use Switon\Core\ContainerInterface;
use Switon\Core\WebFetcherInterface;

#[CoversClass(ServiceProvider::class)]
class ServiceProviderTest extends TestCase
{
    #[Test]
    public function registerBindsWebFetchToHeadlessChrome(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('set')
            ->with(WebFetcherInterface::class, HeadlessChrome::class);

        $provider = new ServiceProvider();
        $provider->register($container);
        $provider->boot();
    }
}
