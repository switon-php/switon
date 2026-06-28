<?php

declare(strict_types=1);

namespace Switon\Db;

use PDOException;
use Switon\Core\OwnerAwareInterface;

/**
 * Low-level connection contract for DB adapters and client operations.
 *
 * Use this interface in infrastructure code; application code should prefer
 * <code>ClientInterface</code>.
 *
 * Guidance: Depend on <code>ClientInterface</code> in application code; use this contract for adapters, tooling, or transport-level customization.
 *
 * Road-signs:
 * - execute()/query() are the raw SQL boundary
 * - begin()/commit()/rollback()/isInTransaction() manage adapter transactions
 * - getMetadata()/getTables()/tableExists() inspect schema state
 * - upsert()/truncate()/drop()/allocateIds() expose adapter-specific helpers
 * - Connection and AbstractConnection are the entry and base implementation
 *
 * @see \Switon\Db\ClientInterface
 * @see \Switon\Db\Connection
 * @see \Switon\Db\AbstractConnection
 */
interface ConnectionInterface extends OwnerAwareInterface
{
    /**
     * Return the DB client that owns this connection in the pool.
     */
    public function getOwner(): ClientInterface;

    /**
     * Get the connection URI string.
     *
     * @return string Database connection URI
     */
    public function getUri(): string;

    /**
     * Execute SQL statement and return affected rows or inserted ID.
     *
     * @param string $sql SQL statement with named parameters (:param)
     * @param array<string, mixed> $bind Parameter values keyed by parameter name (without :)
     * @param bool $has_insert_id True to return last insert ID instead of affected rows
     *
     * @return int Affected rows or inserted ID
     *
     * @throws PDOException On database errors
     */
    public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int;

    /**
     * Execute SELECT query and return result set.
     *
     * @param string $sql SELECT SQL statement with named parameters (:param)
     * @param array<string, mixed> $bind Parameter values keyed by parameter name (without :)
     * @param int $mode PDO fetch mode (FETCH_ASSOC, FETCH_NUM, etc.)
     *
     * @return array<int, array<string|int, mixed>> Array of result rows
     *
     * @throws PDOException On database errors
     */
    public function query(string $sql, array $bind, int $mode): array;

    /**
     * Get table metadata and structure information.
     *
     * @param string $table Table name (should include prefix if applicable)
     *
     * @return array{0: array<string, string>, 1: list<string>, 2: string|null, 3: list<string>} Metadata structure
     *
     * @throws PDOException On database errors or if table doesn't exist
     */
    public function getMetadata(string $table): array;

    /**
     * Begin database transaction.
     *
     * @throws PDOException On transaction start failure
     */
    public function begin(): void;

    /**
     * Commit database transaction.
     *
     * @throws PDOException On commit failure
     */
    public function commit(): void;

    /**
     * Rollback database transaction.
     *
     * @throws PDOException On rollback failure
     */
    public function rollback(): void;

    /**
     * Check if connection is currently in a transaction.
     *
     * @return bool True if in transaction
     */
    public function isInTransaction(): bool;

    /**
     * Truncate table (remove all rows).
     *
     * @param string $table Table name to truncate
     *
     * @throws PDOException On truncate failure
     */
    public function truncate(string $table): void;

    /**
     * Drop table from database.
     *
     * @param string $table Table name to drop
     *
     * @throws PDOException On drop failure
     */
    public function drop(string $table): void;

    /**
     * Get list of tables in database or schema.
     *
     * @param string|null $schema Schema/database name (null = default)
     *
     * @return list<string> Array of table names
     *
     * @throws PDOException On database errors
     */
    public function getTables(?string $schema = null): array;

    /**
     * Check if table exists in database.
     *
     * @param string $table Table name to check
     *
     * @return bool True if table exists, false otherwise
     *
     * @throws PDOException On database errors
     */
    public function tableExists(string $table): bool;

    /**
     * Build SQL query from parameter array.
     *
     * @param array<string, mixed> $params Query parameters (format varies by adapter)
     *
     * @return string Generated SQL query
     *
     * @throws PDOException On invalid parameters or generation errors
     */
    public function buildSql(array $params): string;

    /**
     * Replace quote characters for cross-database compatibility.
     *
     * Converts generic bracket notation [table] to database-specific
     * quote characters for cross-database SQL compatibility.
     *
     * @param string $sql SQL string with bracket notation
     *
     * @return string SQL with database-specific quote characters
     */
    public function convertQuotes(string $sql): string;

    /**
     * Insert-or-update by primary key (adapter-native upsert where available).
     *
     * @param string $table Table name with bracket notation (e.g., '[users]')
     * @param array<string, mixed> $insertFieldValues Values to use for INSERT operation
     * @param array<string|int, mixed> $updateFieldValues Values to use for UPDATE operation.
     *                                                     String keys bind the given value to that column, including strings.
     *                                                     Integer keys reuse the matching value from <code>$insertFieldValues</code>.
     *                                                     Raw SQL expressions are passed through an internal
     *                                                     <code>UpsertExpression</code> value object after
     *                                                     <code>ClientInterface</code> processes <code>SqlFragmentInterface</code>.
     * @param string $primaryKey Primary key field name for conflict detection
     *
     * @return int Number of affected rows
     *
     * @throws PDOException On database errors
     */
    public function upsert(string $table, array $insertFieldValues, array $updateFieldValues, string $primaryKey): int;

    /**
     * Allocate a range of auto-increment IDs for batch insert operations.
     *
     * Reserves IDs from adapter-specific sequence/auto-increment mechanism.
     *
     * @param string $table Table name (without prefix - prefix handled by Client layer)
     * @param string $primaryKey Primary key column name
     * @param int $count Number of IDs to allocate
     *
     * @return array{int, int} Array with [startId, endId] (both inclusive)
     *
     * @throws PDOException On database errors or if table doesn't exist
     * @throws \Switon\Db\Exception If called within a transaction
     * @throws \Switon\Db\Exception If concurrent modification detected
     */
    public function allocateIds(string $table, string $primaryKey, int $count): array;

    /**
     * Return database server version string when available
     *
     * @return string|null Version string or null if not available
     */
    public function getVersion(): ?string;

    /**
     * Check if database connection is alive.
     *
     * Performs a lightweight query to verify connection health.
     *
     * @return bool True if connection is alive and responsive, false otherwise
     */
    public function ping(): bool;

    /**
     * Close the database connection and release resources.
     *
     * Idempotent operation; also resets transaction/prepared-statement state.
     */
    public function close(): void;
}
