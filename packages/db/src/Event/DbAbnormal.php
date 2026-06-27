<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use PDO;
use Switon\Db\ConnectionInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched when a connection closes while still marked in-transaction.
 *
 * Log category: <code>switon.db.abnormal</code>.
 *
 * @see \Switon\Db\AbstractConnection Typical emitter
 * @see \Switon\Db\Event\DbClose Related path
 * @see \Switon\Db\Exception\TransactionNotClosedException Related failure path
 */
#[EventLevel(Severity::WARNING)]
class DbAbnormal implements SenderAwareInterface
{
    public function __construct(
        public ConnectionInterface $sender,
        public string $dsn,
        public string $uri,
        public PDO $pdo,
    ) {
    }

    public function getSender(): ConnectionInterface
    {
        return $this->sender;
    }
}
