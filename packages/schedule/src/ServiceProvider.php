<?php

declare(strict_types=1);

namespace Switon\Schedule;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Registers schedule services and discovers tasks during boot.
 *
 * Bindings follow same-namespace <code>XxxInterface</code> → <code>Xxx</code> auto-mapping; discovery runs in <code>boot()</code> only.
 *
 * Road-signs:
 * - register() relies on namespace auto-mapping
 * - boot() triggers task discovery
 *
 * @see \Switon\Core\ServiceProviderInterface
 * @see \Switon\Schedule\TaskDiscoveryInterface
 * @see \Switon\Schedule\TaskDiscovery
 * @see \Switon\Schedule\Command\ScheduleCommand
 */
class ServiceProvider implements ServiceProviderInterface
{
    #[Autowired] protected TaskDiscoveryInterface $taskDiscovery;

    /** {@inheritDoc} */
    public function register(ContainerInterface $container): void
    {
    }

    /** {@inheritDoc} */
    public function boot(): void
    {
        $this->taskDiscovery->discover();
    }
}
