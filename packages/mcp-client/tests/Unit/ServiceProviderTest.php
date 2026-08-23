<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\McpClient\ClientDiscoveryInterface;
use Switon\McpClient\Importer\ImporterDiscoveryInterface;
use Switon\McpClient\ServiceProvider;
use Switon\McpClient\Tests\TestCase;

#[CoversClass(ServiceProvider::class)]
class ServiceProviderTest extends TestCase
{
    public function testBootDiscoversImportersThenClients(): void
    {
        $importers = new class () implements ImporterDiscoveryInterface {
            public int $calls = 0;

            public function discover(): void
            {
                $this->calls++;
            }
        };
        $clients = new class () implements ClientDiscoveryInterface {
            public int $calls = 0;

            public function discover(): void
            {
                $this->calls++;
            }
        };
        $provider = $this->make(ServiceProvider::class, [
            'importerDiscovery' => $importers,
            'clientDiscovery' => $clients,
        ]);

        $provider->register($this->container);
        $provider->boot();

        $this->assertSame(1, $importers->calls);
        $this->assertSame(1, $clients->calls);
    }
}
