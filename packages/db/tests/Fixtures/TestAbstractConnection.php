<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Fixtures;

use PDO;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\MakerInterface;
use Switon\Db\ClientInterface;
use Switon\Db\AbstractConnection;

class TestAbstractConnection extends AbstractConnection
{
    protected string $dsn = 'sqlite::memory:';
    protected string $username = '';
    protected string $password = '';
    protected bool $emulate_prepares = false;
    protected array $options = [];
    protected ?PDO $pdo = null;
    protected bool $in_transaction = false;
    protected array $prepared = [];
    protected bool $readonly = false;
    protected ?float $last_heartbeat = null;

    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        MakerInterface           $maker,
        string                   $uri = 'test://localhost/test',
        ClientInterface          $owner = new StubDbClient(),
    ) {
        parent::__construct();
        $this->eventDispatcher = $eventDispatcher;
        $this->maker = $maker;
        $this->uri = $uri;
        $this->owner = $owner;
    }

    public function convertQuotes(string $sql): string
    {
        return $sql;
    }

    public function getMetadata(string $table): array
    {
        return [];
    }

    public function getTables(?string $schema = null): array
    {
        return [];
    }

    public function truncate(string $table): void
    {
    }

    public function drop(string $table): void
    {
    }

    public function tableExists(string $table): bool
    {
        return false;
    }

    public function buildSql(array $params): string
    {
        return parent::buildSql($params);
    }

    public function allocateIds(string $table, string $primaryKey, int $count): array
    {
        $startId = 1000;
        return [$startId, $startId + $count - 1];
    }

    public function setPdo(?PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    public function setReadonly(bool $readonly): void
    {
        $this->readonly = $readonly;
    }

    public function setInTransaction(bool $inTransaction): void
    {
        $this->in_transaction = $inTransaction;
    }

    public function testPing(): bool
    {
        return $this->ping();
    }

    public function testEscapeIdentifier(string $identifier): string
    {
        return $this->escapeIdentifier($identifier);
    }

    public function isInTransaction(): bool
    {
        return $this->in_transaction;
    }
}
