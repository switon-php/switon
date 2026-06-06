<?php

declare(strict_types=1);

namespace Switon\Beacon;

use Switon\Beacon\Listener\AiToolRawArgvLogger;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Attribute\ResourceAlias;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;
use Switon\Eventing\ListenerProviderInterface;

/**
 * Registers Beacon listeners for framework bootstrap.
 *
 * Guidance: Beacon command classes stay auto-discoverable; this provider exists to attach Beacon's CLI-side listener during boot.
 *
 * @see \Switon\Core\ServiceProviderInterface
 * @see \Switon\Beacon\Listener\AiToolRawArgvLogger
 * @see \Switon\Eventing\ListenerProviderInterface
 */
#[ResourceAlias]
class ServiceProvider implements ServiceProviderInterface
{
    #[Autowired] protected ListenerProviderInterface $listenerProvider;

    /** {@inheritDoc} */
    public function register(ContainerInterface $container): void
    {
    }

    /** {@inheritDoc} */
    public function boot(): void
    {
        $this->listenerProvider->register(AiToolRawArgvLogger::class);
    }
}
