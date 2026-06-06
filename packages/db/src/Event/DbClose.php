<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use PDO;
use Switon\Db\ConnectionInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * DB connection close event.
 *
 * Log category: <code>switon.db.close</code>.
 *
 * @see \Switon\Db\AbstractConnection Typical emitter
 * @see \Switon\Db\Event\DbAbnormal Related failure path
 */
#[EventLevel(Severity::DEBUG)]
class DbClose
{
    public function __construct(
        public ConnectionInterface $connection,
        public string              $dsn,
        public string              $uri,
        public PDO                 $pdo,
    ) {

    }
}
