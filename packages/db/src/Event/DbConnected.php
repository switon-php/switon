<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use JsonSerializable;
use PDO;
use Switon\Db\ConnectionInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched after PDO connection succeeds.
 *
 * Log category: <code>switon.db.connected</code>.
 *
 * @see \Switon\Db\AbstractConnection
 * @see \Switon\Db\Event\DbConnecting
 */
#[EventLevel(Severity::DEBUG)]
class DbConnected implements JsonSerializable, SenderAwareInterface
{
    protected ConnectionInterface $sender;

    public function __construct(
        ConnectionInterface $sender,
        protected string $dsn,
        protected string $uri,
        protected ?PDO $pdo = null,
    ) {
        $this->sender = $sender;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->sender;
    }

    public function getDsn(): string
    {
        return $this->dsn;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getPdo(): ?PDO
    {
        return $this->pdo;
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
