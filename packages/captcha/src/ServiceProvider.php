<?php

declare(strict_types=1);

namespace Switon\Captcha;

use Switon\Core\Attribute\ResourceAlias;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Registers CAPTCHA package startup wiring.
 *
 * Use when enabling package-owned runtime aliases for bundled fonts.
 *
 * Guidance: Override the service binding in app config when a different adapter, store, or prefix is needed.
 *
 * @see \Switon\Core\ServiceProviderInterface
 */
#[ResourceAlias]
class ServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
    }

    public function boot(): void
    {
    }
}
