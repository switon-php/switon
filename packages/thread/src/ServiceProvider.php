<?php

declare(strict_types=1);

namespace Switon\Thread;

use Switon\Core\Attribute\ResourceAlias;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Registers the thread datadir alias.
 *
 * `@threads` is the runtime datadir root.
 *
 * @see \Switon\Thread\ThreadsInterface
 */
#[ResourceAlias(alias: '@threads', path: '@runtime/threads')]
class ServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
    }

    public function boot(): void
    {
    }
}
