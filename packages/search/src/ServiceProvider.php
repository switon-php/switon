<?php

declare(strict_types=1);

namespace Switon\Search;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Package startup hook. Tools come from composer extra. {@see SitesInterface} supplies prompt variables.
 */
class ServiceProvider implements ServiceProviderInterface
{
    #[Autowired] protected SitesInterface $sites;

    public function register(ContainerInterface $container): void
    {
    }

    public function boot(): void
    {
        $this->sites->reload();
    }
}
