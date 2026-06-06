<?php

declare(strict_types=1);

namespace Switon\Inspector\Collector;

use JsonSerializable;
use Psr\Log\LogLevel;
use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;
use Switon\Eventing\Attribute\EventListener;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Eventing\ObservabilityProbe;
use Switon\Logging\Event\LoggerLogged;
use Switon\Logging\Level;

use function array_slice;
use function date;
use function sprintf;

/**
 * Observability collector for recent log entries.
 *
 * Configure <code>$limit</code> to cap rows returned by inspector output.
 *
 * @see \Switon\Core\CollectorInterface
 * @see \Switon\Logging\Event\LoggerLogged
 */
class LoggerCollector implements CollectorInterface, ObservabilityProbe, ContextAware, JsonSerializable
{
    #[Autowired] protected ContextManagerInterface $contextManager;
    #[Autowired] protected ListenerProviderInterface $listenerProvider;

    /** Max log entries to keep in context (default: 500). */
    #[Autowired] protected int $limit = 500;

    public function getContext(): LoggerCollectorContext
    {
        return $this->contextManager->getContext($this);
    }

    public function boot(): void
    {
        $this->listenerProvider->register($this);
    }

    #[EventListener] public function onLoggerLogged(LoggerLogged $event): void
    {
        $context = $this->getContext();

        $log = $event->logEntry;
        $ms = sprintf('.%03d', ($log->timestamp - (int)$log->timestamp) * 1000);
        $context->logs[] = [
            'time' => date('H:i:s', (int)$log->timestamp) . $ms,
            'level' => $log->level,
            'category' => $log->category,
            'file' => $log->file,
            'line' => $log->line,
            'message' => $log->message
        ];
    }

    /**
     * @return array{
     *     logs: array<int, array{time: string, level: string, category: string, file: string, line: int, message: string}>,
     *     total_count: int,
     *     levels: array<string, int>,
     *     default_level: int
     * }
     */
    public function collect(): array
    {
        $context = $this->getContext();
        $levels = Level::map();

        $logs = $context->logs;
        if (count($logs) > $this->limit) {
            $logs = array_slice($logs, -$this->limit);
        }

        return [
            'logs' => $logs,
            'total_count' => count($context->logs),
            'levels' => $levels,
            'default_level' => $levels[LogLevel::DEBUG],
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [];
    }
}
