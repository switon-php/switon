<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Db\ClientInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * DB transaction commit event.
 *
 * Log category: <code>switon.db.commit</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbBegin
 * @see \Switon\Db\Event\DbRollback
 * @see \Switon\Db\Inspector\SqlCollector Typical consumer
 */
#[EventLevel(Severity::INFO)]
class DbCommit
{
    public function __construct(
        public ClientInterface $client,
    ) {
    }
}
