<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Fixtures;

use Switon\Db\ClientInterface;
use Switon\Db\ConnectionInterface;
use PDOException;

class MockConnection implements ConnectionInterface
{
    protected ClientInterface $owner;

    public function __construct(ClientInterface $owner = new StubDbClient())
    {
        $this->owner = $owner;
    }

    public function getOwner(): ClientInterface
    {
        return $this->owner;
    }

    public string $uri = 'mock://test';
    public array $executedSql = [];
    public array $executedBind = [];
    public array $queryResults = [];
    public int $executeResult = 0;
    public int $insertId = 0;
    public array $tables = [];
    public array $metadata = [];
    public string $buildSqlResult = '';
    public bool $beginThrowsException = false;
    public bool $commitThrowsException = false;
    public bool $rollbackThrowsException = false;
    public bool $inTransaction = false;
    public ?array $lastAllocateIdsCall = null;

    public function getUri(): string
    {
        return $this->uri;
    }

    public function isInTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
    {
        $this->executedSql[] = $sql;
        $this->executedBind[] = $bind;
        return $has_insert_id ? $this->insertId : $this->executeResult;
    }

    public function query(string $sql, array $bind, int $mode): array
    {
        $this->executedSql[] = $sql;
        $this->executedBind[] = $bind;
        return $this->queryResults;
    }

    public function begin(): void
    {
        if ($this->beginThrowsException) {
            throw new PDOException('Transaction start failed');
        }
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        if ($this->commitThrowsException) {
            throw new PDOException('Commit failed');
        }
        $this->inTransaction = false;
    }

    public function rollback(): void
    {
        if ($this->rollbackThrowsException) {
            throw new PDOException('Rollback failed');
        }
        $this->inTransaction = false;
    }

    public function getMetadata(string $table): array
    {
        return $this->metadata;
    }

    public function getTables(?string $schema = null): array
    {
        return $this->tables;
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
        return $this->buildSqlResult;
    }

    public function convertQuotes(string $sql): string
    {
        return $sql;
    }

    public function allocateIds(string $table, string $primaryKey, int $count): array
    {
        $this->lastAllocateIdsCall = [
            'table' => $table,
            'primaryKey' => $primaryKey,
            'count' => $count,
        ];

        $startId = 1000;
        return [$startId, $startId + $count - 1];
    }

    public function upsert(string $table, array $insertFieldValues, array $updateFieldValues, string $primaryKey): int
    {
        $this->executedSql[] = "UPSERT INTO $table";
        $this->executedBind[] = ['insert' => $insertFieldValues, 'update' => $updateFieldValues, 'pk' => $primaryKey];
        return $this->executeResult;
    }

    public function close(): void
    {
    }

    public function getVersion(): ?string
    {
        return null;
    }

    public function ping(): bool
    {
        return true;
    }
}
