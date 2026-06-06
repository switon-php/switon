<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Db\ClientInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Generic SQL completion event with operation class, SQL, and timing.
 *
 * Log category: <code>switon.db.executed</code>.
 *
 * @see \Switon\Db\Client
 * @see \Switon\Db\Event\DbExecuting
 */
#[EventLevel(Severity::DEBUG)]
class DbExecuted
{
    /**
     * @param array<string, mixed> $bind
     */
    public function __construct(
        public ClientInterface $client,
        public string          $event,
        public string          $sql,
        public array           $bind,
        public int             $count,
        public float           $elapsed,
    ) {
    }
}
