<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Browser\ServiceProvider;
use Switon\Core\ContainerInterface;

#[CoversClass(ServiceProvider::class)]
class ServiceProviderTest extends TestCase
{
    #[Test]
    public function registerAndBootAreNoOps(): void
    {
        $provider = new ServiceProvider();
        $provider->register($this->createStub(ContainerInterface::class));
        $provider->boot();

        $this->assertTrue(true);
    }
}
