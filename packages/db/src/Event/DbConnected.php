<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use JsonSerializable;
use PDO;
use Switon\Db\ConnectionInterface;
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
class DbConnected implements JsonSerializable
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string              $dsn,
        protected string              $uri,
        protected ?PDO                $pdo = null,
    ) {

    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
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

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['dsn' => $this->dsn];
    }
}
