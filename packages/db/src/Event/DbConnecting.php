<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use JsonSerializable;
use Switon\Db\ConnectionInterface;
use Switon\Core\SenderAwareInterface;
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
class DbConnecting implements JsonSerializable, SenderAwareInterface
{
    public ConnectionInterface $sender;

    public function __construct(
        ConnectionInterface $sender,
        public string $dsn,
        public string $uri,
    ) {
        $this->sender = $sender;
    }

    public function getSender(): ConnectionInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['dsn' => $this->dsn];
    }
}
