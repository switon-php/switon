<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Fixtures;

use Switon\Db\ClientInterface;

/** @internal Minimal {@see ClientInterface} stand-in for connection owner typing in tests. */
class StubDbClient implements ClientInterface
{
    public function getPrefix(): string
    {
        return '';
    }

    public function getVersion(): ?string
    {
        return null;
    }

    public function getUri(): ?string
    {
        return null;
    }

    public function executeInsert(string $sql, array $bind = []): int
    {
        return 0;
    }

    public function executeUpdate(string $sql, array $bind = []): int
    {
        return 0;
    }

    public function executeDelete(string $sql, array $bind = []): int
    {
        return 0;
    }

    public function fetchAll(string $sql, array $bind = []): array
    {
        return [];
    }

    public function insert(string $table, array $record, bool $fetchInsertId = false): int|string
    {
        return 0;
    }

    public function insertBySql(string $table, string $sql, array $bind = []): int
    {
        return 0;
    }

    public function update(string $table, array $fieldValues, string|array $conditions, array $bind = []): int
    {
        return 0;
    }

    public function updateBySql(string $table, string $sql, array $bind = []): int
    {
        return 0;
    }

    public function upsert(string $table, array $insertFieldValues, array $updateFieldValues = [], ?string $primaryKey = null): int
    {
        return 0;
    }

    public function delete(string $table, string|array $conditions, array $bind = []): int
    {
        return 0;
    }

    public function deleteBySql(string $table, string $sql, array $bind = []): int
    {
        return 0;
    }

    public function begin(): void
    {
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function rollback(): void
    {
    }

    public function commit(): void
    {
    }

    public function getMetadata(string $table): array
    {
        return [[], [], null, []];
    }

    public function getTables(?string $schema = null): array
    {
        return [];
    }

    public function buildSql(array $params): string
    {
        return '';
    }

    public function getTransient(string $type = 'default'): static
    {
        return $this;
    }

    public function allocateIds(string $table, string $primaryKey, int $count): array
    {
        return [1, $count];
    }

    public function close(): void
    {
    }
}
