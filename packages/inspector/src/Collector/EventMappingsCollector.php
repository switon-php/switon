<?php

declare(strict_types=1);

namespace Switon\Inspector\Collector;

use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;
use Switon\Eventing\EventLoggerInterface;
use Switon\Eventing\ObservabilityProbe;

/**
 * Collects event logger category and level mappings for inspector output.
 *
 * @see \Switon\Core\CollectorInterface
 * @see \Switon\Eventing\EventLogger
 */
class EventMappingsCollector implements CollectorInterface, ObservabilityProbe
{
    #[Autowired] protected EventLoggerInterface $eventLogger;

    public function boot(): void
    {
    }

    public function collect(): array
    {
        return [
            'categories' => $this->eventLogger->getCategoryMapping(),
            'levels' => $this->eventLogger->getLevelMapping(),
        ];
    }
}
