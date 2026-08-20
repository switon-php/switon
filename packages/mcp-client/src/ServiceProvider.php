<?php

declare(strict_types=1);

namespace Switon\McpClient;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Boot: read `mcp.yml` into {@see ClientsInterface}.
 */
class ServiceProvider implements ServiceProviderInterface
{
    #[Autowired] protected ClientDiscoveryInterface $clientDiscovery;

    public function register(ContainerInterface $container): void
    {
    }

    public function boot(): void
    {
        $this->clientDiscovery->discover();
    }
}
