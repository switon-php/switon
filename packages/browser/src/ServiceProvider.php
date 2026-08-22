<?php

declare(strict_types=1);

namespace Switon\Browser;

use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Convention binds {@see HeadedBrowserInterface} and {@see HeadedBrowsersInterface}. Tools use the latter,
 * one {@see HeadedBrowser} per agent-chosen tab id.
 */
class ServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
    }

    public function boot(): void
    {
    }
}
