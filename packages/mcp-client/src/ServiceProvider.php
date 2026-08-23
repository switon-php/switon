<?php

declare(strict_types=1);

namespace Switon\McpClient;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;
use Switon\McpClient\Importer\ImporterDiscoveryInterface;

/**
 * Boot: register importers from extra, then read `mcp.yml` into {@see ClientsInterface}.
 */
class ServiceProvider implements ServiceProviderInterface
{
    #[Autowired] protected ImporterDiscoveryInterface $importerDiscovery;
    #[Autowired] protected ClientDiscoveryInterface $clientDiscovery;

    public function register(ContainerInterface $container): void
    {
    }

    public function boot(): void
    {
        $this->importerDiscovery->discover();
        $this->clientDiscovery->discover();
    }
}
