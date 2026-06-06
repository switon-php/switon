<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Db\ClientInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * DB transaction begin event.
 *
 * Log category: <code>switon.db.begin</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbCommit
 * @see \Switon\Db\Event\DbRollback
 * @see \Switon\Db\Inspector\SqlCollector Typical consumer
 */
#[EventLevel(Severity::INFO)]
class DbBegin
{
    public function __construct(
        public ClientInterface $client,
    ) {
    }
}
