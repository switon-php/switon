<?php

declare(strict_types=1);

namespace Switon\Db;

/**
 * Contract for direct SQL execution, prefixed CRUD, and local transaction control in DB clients.
 *
 * Use this API in application services. It applies table prefixes, named-parameter binding,
 * read/write routing, and transient-client transaction pinning automatically.
 *
 * Raw SQL identifiers can use <code>[name]</code> notation; adapters convert it to
 * driver-specific quoting.
 *
 * Guidance: Prefer ORM repositories and entity managers for routine domain persistence; use this API for raw SQL, infrastructure queries, or ORM escape hatches.
 *
 * Road-signs:
 * - EntityManager/Repository are the usual business-layer entrypoints
 * - fetchAll()/execute* for direct SQL
 * - insert()/update()/upsert()/delete() for prefixed CRUD
 * - getTransient()/begin()/commit()/rollback() for connection-pinned local transactions
 * - getMetadata()/getTables()/buildSql() for inspection and builders
 * - TransactionManagerInterface for ORM transaction scopes
 *
 * @see \Switon\Db\Client
 * @see \Switon\Di\NamedLookupInterface
 * @see \Switon\Orm\EntityManagerInterface
 * @see \Switon\Query\QueryInterface
 * @see \Switon\Query\Query::execute()
 * @see \Switon\Db\ClientInterface::fetchAll()
 * @see \Switon\Db\ClientInterface::buildSql()
 * @see \Switon\Orm\EntityManager Typical consumer
 * @see \Switon\Query\Query Typical consumer
 * @see \Switon\Db\TransactionManagerInterface
 */
interface ClientInterface
{
    /**
     * Returns the configured table prefix.
     *
     * @return string Empty string when no prefix is configured
     */
    public function getPrefix(): string;

    /**
     * Return database server version string when available.
     *
     * @return string|null Version string or null if not available
     */
    public function getVersion(): ?string;

    /**
     * Return URI for inspection.
     *
     * @return string|null URI or null when not available
     */
    public function getUri(): ?string;

    /**
     * Execute INSERT SQL statement with parameter binding.
     *
     * @param string $sql INSERT SQL statement with named parameters (:param)
     * @param array<string, mixed> $bind Parameter values keyed by parameter name (without :)
     *
     * @return int Number of affected rows
     *
     * @throws \Switon\Db\Exception On database errors
     */
    public function executeInsert(string $sql, array $bind = []): int;

    /**
     * Execute UPDATE SQL statement with parameter binding.
     *
     * @param string $sql UPDATE SQL statement with named parameters (:param)
     * @param array<string, mixed> $bind Parameter values keyed by parameter name (without :)
     *
     * @return int Number of affected rows
     *
     * @throws \Switon\Db\Exception On database errors
     */
    public function executeUpdate(string $sql, array $bind = []): int;

    /**
     * Execute DELETE SQL statement with parameter binding.
     *
     * @param string $sql DELETE SQL statement with named parameters (:param)
     * @param array<string, mixed> $bind Parameter values keyed by parameter name (without :)
     *
     * @return int Number of affected rows
     *
     * @throws \Switon\Db\Exception On database errors
     */
    public function executeDelete(string $sql, array $bind = []): int;

    /**
     * Execute SELECT query and return all rows.
     *
     * Uses read routing by default. During a transaction, reads use the pinned
     * transient transaction client.
     *
     * @param string $sql SELECT SQL statement with named parameters (:param)
     * @param array<string, mixed> $bind Parameter values keyed by parameter name (without :)
     *
     * @return list<array<string, mixed>> Associative result rows, empty list if no results
     *
     * @throws \Switon\Db\Exception On database errors
     */
    public function fetchAll(string $sql, array $bind = []): array;

    /**
     * Insert a single record into a table.
     *
     * Applies table prefix automatically.
     *
     * @param string $table Table name (prefix will be automatically applied)
     * @param array<string, mixed> $record Associative array of column => value pairs
     * @param bool $fetchInsertId Whether to return inserted ID instead of affected rows
     *
     * @return int|string Inserted ID (int or string for large IDs) if $fetchInsertId=true, otherwise affected row count (int); returns 0 when $record is empty
     *
     * @throws \Switon\Db\Exception On database errors
     */
    public function insert(string $table, array $record, bool $fetchInsertId = false): int|string;

    /**
     * Insert records using custom SQL fragment.
     *
     * Use this for custom INSERT expressions while preserving prefix handling.
     *
     * @param string $table Table name (prefix will be automatically applied)
     * @param string $sql SQL fragment (everything after "INSERT INTO table_name")
     * @param array<string, mixed> $bind Parameter values keyed by parameter name (without :)
     *
     * @return int Number of affected rows
     *
     * @throws \Switon\Db\Exception On database errors
     */
    public function insertBySql(string $table, string $sql, array $bind = []): int;

    /**
     * Update records in a table with conditions.
     *
     * Rejects empty/unsafe conditions to prevent full-table updates.
     *
     * @param string $table Table name (prefix will be automatically applied)
     * @param array<string|int, mixed> $fieldValues Associative array of column => value pairs (supports SqlFragmentInterface objects)
     * @param string|array<string|int, mixed> $conditions WHERE conditions (associative array or raw SQL strings)
     * @param array<string, mixed> $bind Additional parameter values for conditions
     *
     * @return int Number of affected rows; returns 0 when $fieldValues is empty
     *
     * @throws \Switon\Db\Exception\UnsafeOperationException If $conditions is empty
     * @throws \Switon\Db\Exception On database errors
     */
    public function update(string $table, array $fieldValues, string|array $conditions, array $bind = []): int;

    /**
     * Update records using custom SQL fragment.
     *
     * Use this for custom UPDATE expressions while preserving prefix handling.
     *
     * @param string $table Table name (prefix will be automatically applied)
     * @param string $sql SQL fragment (everything after "UPDATE table_name SET")
     * @param array<string, mixed> $bind Parameter values keyed by parameter name (without :)
     *
     * @return int Number of affected rows
     *
     * @throws \Switon\Db\Exception On database errors
     */
    public function updateBySql(string $table, string $sql, array $bind = []): int;

    /**
     * Insert or update a record based on primary key existence.
     *
     * Uses adapter-native upsert when available, otherwise fallback logic.
     * Associative entries use <code>column => value</code> semantics and bind regular values,
     * including strings. Integer keys mean "reuse the INSERT value of this field". To pass a
     * raw SQL update expression, use <code>SqlFragmentInterface</code>; field names are stringified from keys.
     *
     * @param string $table Table name (prefix will be automatically applied)
     * @param array<string, mixed> $insertFieldValues Values to use for INSERT operation
     * @param array<string|int, mixed> $updateFieldValues Values to use for UPDATE operation (empty = use insert values).
     *                                                     String keys bind the given value to that column. Integer keys
     *                                                     reuse the matching value from <code>$insertFieldValues</code>.
     *                                                     <code>SqlFragmentInterface</code> values MUST use string keys.
     * @param string|null $primaryKey Primary key field name (null = use first insert field)
     *
     * @return int Number of affected rows (1 for insert, 0-1 for update)
     *
     * @throws \Switon\Db\Exception On database errors
     */
    public function upsert(string $table, array $insertFieldValues, array $updateFieldValues = [], ?string $primaryKey = null): int;

    /**
     * Delete records from a table with conditions.
     *
     * Rejects empty/unsafe conditions to prevent full-table deletes.
     *
     * @param string $table Table name (prefix will be automatically applied)
     * @param string|array<string|int, mixed> $conditions WHERE conditions (associative array or raw SQL strings)
     * @param array<string, mixed> $bind Additional parameter values for conditions
     *
     * @return int Number of affected rows
     *
     * @throws \Switon\Db\Exception\UnsafeOperationException If $conditions is empty or unsafe
     * @throws \Switon\Db\Exception On database errors
     */
    public function delete(string $table, string|array $conditions, array $bind = []): int;

    /**
     * Delete records using custom SQL fragment.
     *
     * Use this for custom DELETE expressions while preserving prefix handling.
     *
     * @param string $table Table name (prefix will be automatically applied)
     * @param string $sql SQL fragment (everything after "DELETE FROM table_name")
     * @param array<string, mixed> $bind Parameter values keyed by parameter name (without :)
     *
     * @return int Number of affected rows
     *
     * @throws \Switon\Db\Exception On database errors
     */
    public function deleteBySql(string $table, string $sql, array $bind = []): int;

    /**
     * Begin a database transaction.
     *
     * Supported on transient clients returned by {@see self::getTransient()}.
     * Use <code>getTransient('default')</code> for transactional writes.
     *
     * @throws \Switon\Db\Exception On transaction start failure
     */
    public function begin(): void;

    /**
     * Check if currently within a transaction.
     *
     * @return bool True if within transaction, false otherwise
     */
    public function inTransaction(): bool;

    /**
     * Rollback the current transaction.
     *
     * Supported on transient clients. Calling rollback without an active
     * transaction is a no-op.
     *
     * @throws \Switon\Db\Exception On database rollback errors
     *
     * @see TransactionManagerInterface::rollback() For ORM-level transaction rollback
     */
    public function rollback(): void;

    /**
     * Commit the current transaction.
     *
     * Supported on transient clients. Commits when transaction nesting level
     * reaches zero.
     *
     * @throws \Switon\Db\Exception\NoActiveTransactionException If no active transaction
     * @throws \Switon\Db\Exception On database commit errors
     */
    public function commit(): void;

    /**
     * Get table metadata including structure and constraints.
     *
     * Always queries the database and does not cache metadata.
     * Keys in returned tuple match <code>Client::METADATA_*</code> constants.
     *
     * @param string $table Table name (prefix will be automatically applied)
     *
     * @return array{0: array<string, string>, 1: list<string>, 2: string|null, 3: list<string>} Metadata array with structure information
     *
     * @throws \Switon\Db\Exception On database errors or if table doesn't exist
     */
    public function getMetadata(string $table): array;

    /**
     * Get list of all tables in database or schema.
     *
     * Always queries the database and applies configured table-prefix filtering.
     * Pass plain schema/database name (without quoting).
     *
     * @param string|null $schema Schema/database name (null = default schema)
     *
     * @return list<string> Array of table names (with prefix removed if applicable)
     *
     * @throws \Switon\Db\Exception On database errors
     */
    public function getTables(?string $schema = null): array;

    /**
     * Build SQL query from parameter array.
     *
     * Constructs SQL queries from structured parameter arrays. Used by
     * query builders and ORM implementations.
     *
     * @param array<string, mixed> $params Query parameters structure (adapter-specific format)
     *
     * @return string Generated SQL query string
     *
     * @throws \Switon\Db\Exception On invalid parameters or SQL generation errors
     */
    public function buildSql(array $params): string;

    /**
     * Create transient client with dedicated connection.
     *
     * Transient clients pin a dedicated pooled connection for their lifetime.
     * Transaction APIs are intended for transient clients.
     *
     * @param string $type Pool type: 'default', 'slave', or 'auto' (auto uses slave if available; not suitable for transactions)
     *
     * @return static Transient client instance
     *
     * @throws \Switon\Pooling\Exception\BusyException If pool is busy
     */
    public function getTransient(string $type = 'default'): static;

    /**
     * Allocate a range of auto-increment IDs for batch insert operations.
     *
     * Uses database-specific sequence/auto-increment allocation and returns
     * inclusive range <code>[startId, endId]</code>.
     * Uses a separate master connection and should be used outside transactions.
     *
     * @param string $table Table name (prefix will be automatically applied)
     * @param string $primaryKey Primary key column name (for sequence detection in PostgreSQL)
     * @param int $count Number of IDs to allocate
     *
     * @return array{int, int} Array with [startId, endId] (both inclusive)
     *
     * @throws \Switon\Db\Exception If concurrent modification detected
     * @throws \Switon\Db\Exception On database errors or if table doesn't exist
     */
    public function allocateIds(string $table, string $primaryKey, int $count): array;

    /**
     * Close database connection and clean up resources.
     *
     * Rolls back uncommitted transaction state and releases held resources.
     *
     * @throws \Switon\Db\Exception On connection close errors (rare)
     */
    public function close(): void;
}
