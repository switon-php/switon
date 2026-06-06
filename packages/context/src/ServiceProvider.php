<?php

declare(strict_types=1);

namespace Switon\Context;

use Switon\Core\ContainerInterface;
use Switon\Core\ContextManagerInterface as CoreContextManagerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Integrates context manager bindings during application startup.
 *
 * Guidance: The core contract stays the public DI entrypoint; this provider routes it to the package helper interface and implementation.
 *
 * @see \Switon\Core\ServiceProviderInterface
 * @see \Switon\Core\ContextManagerInterface
 * @see \Switon\Context\ContextManagerInterface
 * @see \Switon\Context\ContextManager
 */
class ServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritDoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Core contract and default implementation live in different namespaces.
        $container->set(CoreContextManagerInterface::class, ContextManagerInterface::class);
    }

    /**
     * {@inheritDoc}
     */
    public function boot(): void
    {
        // Context package does not require boot logic
    }
}
