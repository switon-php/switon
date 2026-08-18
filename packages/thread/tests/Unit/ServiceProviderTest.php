<?php

declare(strict_types=1);

namespace Switon\Thread\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Thread\ServiceProvider;
use Switon\Thread\Tests\TestCase;

#[CoversClass(ServiceProvider::class)]
class ServiceProviderTest extends TestCase
{
    public function testRegisterAndBootAreIdle(): void
    {
        $this->expectNotToPerformAssertions();

        $provider = new ServiceProvider();
        $provider->register($this->container);
        $provider->boot();
    }
}
