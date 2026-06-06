<?php

declare(strict_types=1);

namespace Switon\Routing\Inspector;

use JsonSerializable;
use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;
use Switon\Routing\RouterInterface;

/**
 * Collector that exposes registered router routes for inspector output.
 *
 * @see \Switon\Routing\RouterInterface
 * @see \Switon\Routing\RouterInterface::getAllRoutes()
 */
class RouterCollector implements CollectorInterface, JsonSerializable
{
    #[Autowired] protected RouterInterface $router;

    public function boot(): void
    {
        // No initialization needed
    }

    public function collect(): array
    {
        return $this->router->getAllRoutes();
    }

    /** @return array<int|string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->collect();
    }
}
