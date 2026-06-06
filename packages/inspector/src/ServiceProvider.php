<?php

declare(strict_types=1);

namespace Switon\Inspector;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Attribute\ResourceAlias;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Boots inspector lifecycle services after container wiring completes.
 *
 * Guidance:
 * - bindings come from package config and aliases
 * - this provider only triggers inspector boot
 *
 * Road-signs:
 * - InspectorInterface->boot()
 *
 * @see \Switon\Core\ServiceProviderInterface
 */
#[ResourceAlias]
class ServiceProvider implements ServiceProviderInterface
{
    #[Autowired] protected InspectorInterface $inspector;

    /**
     * Keeps registration as a no-op because inspector bindings come from config resources.
     */
    public function register(ContainerInterface $container): void
    {
        // InspectorInterface is registered in config files
    }

    /**
     * Boots the inspector coordinator so request listeners and collectors become active.
     */
    public function boot(): void
    {
        $this->inspector->boot();
    }
}
