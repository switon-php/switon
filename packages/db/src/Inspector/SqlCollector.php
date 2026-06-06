<?php

declare(strict_types=1);

namespace Switon\Db\Inspector;

use JsonSerializable;
use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;
use Switon\Core\Lazy;
use Switon\Db\Event\DbBegin;
use Switon\Db\Event\DbCommit;
use Switon\Db\Event\DbExecuted;
use Switon\Db\Event\DbExecuting;
use Switon\Db\Event\DbQueried;
use Switon\Db\Event\DbQuerying;
use Switon\Db\Event\DbRollback;
use Switon\Db\PreparedEmulatorInterface;
use Switon\Eventing\Attribute\EventListener;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Eventing\ObservabilityProbe;

/**
 * Collects DB query timeline and SQL frequency data for observability output.
 *
 * Tracks prepared SQL frequency and executed statements with elapsed/row-count data.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbQuerying
 * @see \Switon\Db\Event\DbQueried
 * @see \Switon\Db\Event\DbExecuting
 * @see \Switon\Db\Event\DbExecuted
 * @see \Switon\Db\Event\DbBegin
 * @see \Switon\Db\Event\DbCommit
 * @see \Switon\Db\Event\DbRollback
 * @see \Switon\Eventing\ListenerProviderInterface::register() Registration boundary
 */
class SqlCollector implements CollectorInterface, ObservabilityProbe, ContextAware, JsonSerializable
{
    #[Autowired] protected ContextManagerInterface $contextManager;
    #[Autowired] protected PreparedEmulatorInterface|Lazy $preparedEmulator;
    #[Autowired] protected ListenerProviderInterface $listenerProvider;

    public function getContext(): SqlCollectorContext
    {
        return $this->contextManager->getContext($this);
    }

    public function boot(): void
    {
        // Register event listener
        $this->listenerProvider->register($this);
    }

    #[EventListener] public function onDb(object $event): void
    {
        $context = $this->getContext();

        if ($event instanceof DbQuerying || $event instanceof DbExecuting) {
            $preparedSQL = $event->sql;
            if (!isset($context->prepared[$preparedSQL])) {
                $context->prepared[$preparedSQL] = 1;
            } else {
                $context->prepared[$preparedSQL]++;
            }

            $context->count++;

            $sql = $event->sql;
            $bind = $event->bind;
            $context->executed[] = [
                'prepared' => $sql,
                'bind' => $bind,
                'emulated' => $this->preparedEmulator->emulate($sql, $bind, 128)
            ];
        } elseif ($event instanceof DbQueried || $event instanceof DbExecuted) {
            $context->executed[$context->count - 1]['elapsed'] = $event->elapsed;
            $context->executed[$context->count - 1]['row_count'] = $event->count;
        } elseif ($event instanceof DbBegin || $event instanceof DbCommit || $event instanceof DbRollback) {
            $context->count++;

            $name = $event::class;

            $context->executed[] = [
                'prepared' => $name,
                'bind' => [],
                'emulated' => $name,
                'elapsed' => 0,
                'row_count' => 0
            ];

            $preparedSQL = $name;
            if (!isset($context->prepared[$preparedSQL])) {
                $context->prepared[$preparedSQL] = 1;
            } else {
                $context->prepared[$preparedSQL]++;
            }
        }
    }

    /**
     * @return array{prepared: array<string, int>, executed: list<array<string, mixed>>, count: int}
     */
    public function collect(): array
    {
        $context = $this->getContext();

        return [
            'prepared' => $context->prepared,
            'executed' => $context->executed,
            'count' => $context->count
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [];
    }
}
