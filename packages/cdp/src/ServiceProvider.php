<?php

declare(strict_types=1);

namespace Switon\Cdp;

use Switon\Core\WebFetcherInterface;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Binds the browser-driver contract that lives in {@see \Switon\Core}.
 *
 * Road-signs:
 * - {@see WebFetcherInterface} → {@see HeadlessChrome}
 *
 * The other two services resolve by same-namespace convention:
 * {@see HeadlessChromeInterface} → {@see HeadlessChrome},
 * {@see HeadedChromeInterface} → {@see HeadedChrome}.
 */
class ServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // A page reader asks for the capability, not for Chrome. Binding it here keeps the
        // caller's dependency on `switon/core` alone.
        $container->set(WebFetcherInterface::class, HeadlessChrome::class);
    }

    public function boot(): void
    {
    }
}
