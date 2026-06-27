<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use PDO;
use Switon\Db\ConnectionInterface;
use Switon\Core\SenderAwareInterface;
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
class DbClose implements SenderAwareInterface
{
    public ConnectionInterface $sender;

    public function __construct(
        ConnectionInterface $sender,
        public string $dsn,
        public string $uri,
        public PDO $pdo,
    ) {
        $this->sender = $sender;
    }

    public function getSender(): ConnectionInterface
    {
        return $this->sender;
    }
}
