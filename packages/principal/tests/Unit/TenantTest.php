<?php

declare(strict_types=1);

namespace Switon\Principal\Tests\Unit;

use Switon\Core\Exception\InvalidArgumentException;
use Switon\Principal\Exception\TenantNotAvailableException;
use Switon\Principal\Tenant;
use Switon\Principal\TenantInterface;
use Switon\Principal\Tests\TestCase;

class TenantTest extends TestCase
{
    public function testTenantStartsAsTenantless(): void
    {
        $tenant = $this->container->make(Tenant::class);

        $this->assertFalse($tenant->hasTenant());
    }

    public function testTenantSetStoresNormalizedFields(): void
    {
        $tenant = $this->container->make(Tenant::class);

        $tenant->set(7, ' acme ');

        $this->assertTrue($tenant->hasTenant());
        $this->assertSame(7, $tenant->getId());
        $this->assertSame('acme', $tenant->getCode());
    }

    public function testTenantAllowsSameValueForIdAndCode(): void
    {
        $tenant = $this->container->make(Tenant::class);

        $tenant->set('acme', 'acme');

        $this->assertSame('acme', $tenant->getId());
        $this->assertSame('acme', $tenant->getCode());
    }

    public function testTenantClearReturnsToTenantlessState(): void
    {
        $tenant = $this->container->get(TenantInterface::class);
        $tenant->set(7, 'acme');
        $tenant->clear();

        $this->assertFalse($tenant->hasTenant());
    }

    public function testTenantClearMakesTenantCodeUnavailableAgain(): void
    {
        $tenant = $this->container->make(Tenant::class);
        $tenant->set(7, 'acme');
        $tenant->clear();

        $this->expectException(TenantNotAvailableException::class);
        $tenant->getCode();
    }

    public function testTenantThrowsWhenTenantFieldsAreAccessedWithoutTenant(): void
    {
        $tenant = $this->container->make(Tenant::class);

        $this->expectException(TenantNotAvailableException::class);
        $tenant->getId();
    }

    public function testTenantThrowsWhenTenantCodeIsAccessedWithoutTenant(): void
    {
        $tenant = $this->container->make(Tenant::class);

        $this->expectException(TenantNotAvailableException::class);
        $tenant->getCode();
    }

    public function testTenantRejectsEmptyCode(): void
    {
        $tenant = $this->container->make(Tenant::class);

        $this->expectException(InvalidArgumentException::class);
        $tenant->set(7, '   ');
    }

    public function testTenantRejectsEmptyStringId(): void
    {
        $tenant = $this->container->make(Tenant::class);

        $this->expectException(InvalidArgumentException::class);
        $tenant->set('   ', 'acme');
    }
}
