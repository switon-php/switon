<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use JsonSerializable;
use Switon\Db\ConnectionInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched right before PDO connection is attempted.
 *
 * Log category: <code>switon.db.connecting</code>.
 *
 * @see \Switon\Db\AbstractConnection
 * @see \Switon\Db\Event\DbConnected
 */
#[EventLevel(Severity::DEBUG)]
class DbConnecting implements JsonSerializable
{
    public function __construct(
        public ConnectionInterface $connection,
        public string              $dsn,
        public string              $uri,
    ) {

    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['dsn' => $this->dsn];
    }
}
