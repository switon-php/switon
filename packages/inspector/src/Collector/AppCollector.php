<?php

declare(strict_types=1);

namespace Switon\Inspector\Collector;

use JsonSerializable;
use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;

/**
 * Collect application metadata for inspector output.
 *
 * @see \Switon\Core\CollectorInterface
 * @see \Switon\Core\AppInterface
 */
class AppCollector implements CollectorInterface, JsonSerializable
{
    #[Autowired] protected AppInterface $app;

    public function boot(): void
    {
        // No initialization needed
    }

    public function collect(): array
    {
        return [
            'id' => $this->app->id(),
            'name' => $this->app->name(),
            'version' => $this->app->version(),
            'timezone' => $this->app->timezone(),
            'env' => $this->app->env(),
            'debug' => $this->app->isDebug(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [];
    }
}
