<?php

declare(strict_types=1);

namespace Switon\Tooling;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Runs {@see ToolDiscoveryInterface} during boot.
 */
class ServiceProvider implements ServiceProviderInterface
{
    #[Autowired] protected ToolDiscoveryInterface $toolDiscovery;

    public function register(ContainerInterface $container): void
    {
    }

    public function boot(): void
    {
        $this->toolDiscovery->discover();
    }
}
