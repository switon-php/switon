<?php

declare(strict_types=1);

namespace Switon\Db;

use PDO;
use PDOException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Db\Event\DbBegin;
use Switon\Db\Event\DbCommit;
use Switon\Db\Event\DbDeleted;
use Switon\Db\Event\DbDeleting;
use Switon\Db\Event\DbExecuted;
use Switon\Db\Event\DbExecuting;
use Switon\Db\Event\DbInserted;
use Switon\Db\Event\DbInserting;
use Switon\Db\Event\DbMetadata;
use Switon\Db\Event\DbQueried;
use Switon\Db\Event\DbQuerying;
use Switon\Db\Event\DbRollback;
use Switon\Db\Event\DbUpdated;
use Switon\Db\Event\DbUpdating;
use Switon\Db\Event\DbUpserted;
use Switon\Db\Event\DbUpserting;
use Switon\Db\Exception as DbException;
use Switon\Db\Exception\NoActiveTransactionException;
use Switon\Db\Exception\TransientClientException;
use Switon\Db\Exception\UnsafeOperationException;
use Switon\Pooling\PoolGuard;
use Switon\Pooling\PoolManagerInterface;

use function array_keys;
use function count;
use function implode;
use function is_int;
use function is_string;
use function key;
use function preg_match;
use function str_starts_with;
use function stripos;
use function strlen;
use function strpos;
use function substr;

/**
 * Implements database CRUD and query APIs with prefixing, bind handling, and read/write routing.
 *
 * Use when you need CRUD APIs with automatic table prefixing, parameter binding,
 * read/write routing, and transaction-aware connection pinning.
 *
 * Guidance: Prefer ORM-facing APIs for normal entity work; use this client for direct SQL, framework internals, or ORM escape hatches, and start transactions on <code>getTransient('default')</code>.
 *
 * Road-signs:
 * - EntityManager/Repository are the usual callers above this layer
 * - getReadConnection()/getWriteConnection() choose the active connection
 * - fetchAll()/execute* wrap direct SQL with events
 * - insert()/update()/upsert()/delete() normalize CRUD input
 * - SqlFragmentInterface is expanded before update/upsert reach adapters
 * - getTransient() pins one connection for begin()/commit()/rollback()
 *
 * @see \Switon\Db\ClientInterface
 * @see \Switon\Db\ClientInterface::fetchAll()
 * @see \Switon\Db\ClientInterface::buildSql()
 * @see \Switon\Db\ClientInterface::getTransient()
 * @see \Switon\Db\Exception
 * @see \Switon\Db\TransactionManagerInterface
 * @see \Switon\Orm\EntityManagerInterface
 * @see \Switon\Query\Query::execute()
 * @see \Switon\Db\Event\AbstractDbExecuting
 * @see \Switon\Db\Event\AbstractDbExecuted
 * @see \Switon\Db\PoolConfiguratorInterface
 * @see \Switon\Db\ConnectionInterface
 * @see \Switon\Pooling\PoolManagerInterface
 */
class Client implements ClientInterface
{
    /** Special whitelist keyword for safe raw condition <code>WHERE FALSE</code>. */
    protected const string SQL_FALSE_KEYWORD = 'FALSE';

    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected ClockInterface $clock;
    /** @var PoolManagerInterface<Client, ConnectionInterface> */
    #[Autowired] protected PoolManagerInterface $poolManager;
    #[Autowired] protected PoolConfiguratorInterface $poolConfigurator;

    public const int METADATA_ATTRIBUTES = 0;
    public const int METADATA_PRIMARY_KEY = 1;
    public const int METADATA_AUTO_INCREMENT_KEY = 2;
    public const int METADATA_INT_TYPE_ATTRIBUTES = 3;

    /** Database connection URI used to configure pools. */
    #[Autowired] protected string $uri;

    protected string $prefix = '';
    protected float $timeout = 1.0;
    protected bool $has_slave = false;
    protected ?ConnectionInterface $connection = null;
    protected int $transaction_level = 0;

    /** @var PoolGuard<Client, ConnectionInterface>|null Pool guard for transient clients; non-null means this instance pins a dedicated connection. */
    protected ?PoolGuard $guard = null;

    /**
     * Initialize database client with connection pool configuration.
     *
     * Delegates URI parsing and pool configuration to PoolConfigurator.
     * The actual database connections are created lazily when first needed.
     */
    /** @noinspection PhpTypedPropertyMightBeUninitializedInspection */
    public function __construct()
    {
        $config = $this->poolConfigurator->configure($this, $this->uri);

        $this->prefix = $config->prefix;
        $this->timeout = $config->timeout;
        $this->has_slave = $config->hasSlave;
    }


    /**
     * Create transient client with dedicated connection.
     *
     * Creates a transient instance that holds a dedicated connection for its lifetime.
     *
     * @return static Transient client instance
     */
    public function getTransient(string $type = 'default'): static
    {
        $base = $this->guard->parent ?? $this;

        if ($type === 'auto') {
            $type = $base->has_slave ? 'slave' : 'default';
        } elseif ($type === 'slave' && !$base->has_slave) {
            $type = 'default';
        }

        /** @var static $transient */
        $transient = clone $base;
        $transient->guard = $this->poolManager->guard($base, $base->timeout, $type);
        $transient->connection = $transient->guard->resource;
        $transient->transaction_level = 0;

        return $transient;
    }


    /**
     * Get read connection with automatic resource management.
     *
     * Returns a connection for read operations. Uses PoolGuard's __call proxy
     * for automatic pool return when the returned object goes out of scope.
     *
     * @return ConnectionInterface|PoolGuard<Client, ConnectionInterface> Connection (PoolGuard auto-returns to pool)
     */
    protected function getReadConnection(): ConnectionInterface|PoolGuard
    {
        if ($this->guard) {
            return $this->guard->resource;
        }

        if ($this->connection) {
            return $this->connection;
        }

        $type = $this->has_slave ? 'slave' : 'default';
        return $this->poolManager->guard($this, $this->timeout, $type);
    }

    /**
     * Get write connection with automatic resource management.
     *
     * Returns a master connection for write operations. Uses PoolGuard's __call proxy
     * for automatic pool return when the returned object goes out of scope.
     *
     * @return ConnectionInterface|PoolGuard<Client, ConnectionInterface> Connection (PoolGuard auto-returns to pool)
     */
    protected function getWriteConnection(): ConnectionInterface|PoolGuard
    {
        if ($this->guard) {
            return $this->guard->resource;
        }

        if ($this->connection) {
            return $this->connection;
        }

        return $this->poolManager->guard($this, $this->timeout);
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getVersion(): ?string
    {
        return $this->getReadConnection()->getVersion();
    }

    public function getUri(): ?string
    {
        return $this->getReadConnection()->getUri();
    }

    /**
     * Executes UPDATE/DELETE statements with before/after DB events.
     *
     * @param array<string, mixed> $bind
     */
    protected function execute(string $doing, string $done, string $sql, array $bind = []): int
    {
        $this->eventDispatcher->dispatch(new DbExecuting($this, $doing, $sql, $bind));
        $this->eventDispatcher->dispatch(new $doing($this, $sql, $bind));

        $connection = $this->getWriteConnection();

        $startTime = $this->clock->microtime();
        $count = $connection->execute($sql, $bind);
        $elapsed = $this->clock->elapsed($startTime);

        $this->eventDispatcher->dispatch(new $done($this, $sql, $bind, $count, $elapsed));
        $this->eventDispatcher->dispatch(new DbExecuted($this, $done, $sql, $bind, $count, $elapsed));

        return $count;
    }

    /**
     * Executes INSERT statements with events and optional insert-id return.
     *
     * @param array<string, mixed> $bind
     */
    protected function executeInsertInternal(string $sql, array $bind = [], bool $fetchInsertId = false): int|string
    {
        $this->eventDispatcher->dispatch(new DbExecuting($this, DbInserting::class, $sql, $bind));
        $this->eventDispatcher->dispatch(new DbInserting($this, $sql, $bind));

        $connection = $this->getWriteConnection();

        $startTime = $this->clock->microtime();
        if ($fetchInsertId) {
            $result = $connection->execute($sql, $bind, true);
            $affectedRows = 1;
        } else {
            $result = $connection->execute($sql, $bind);
            $affectedRows = $result;
        }
        $elapsed = $this->clock->elapsed($startTime);

        $this->eventDispatcher->dispatch(new DbInserted($this, $sql, $bind, $affectedRows, $elapsed));
        $this->eventDispatcher->dispatch(new DbExecuted($this, DbInserted::class, $sql, $bind, $affectedRows, $elapsed));

        return $result;
    }

    public function executeInsert(string $sql, array $bind = []): int
    {
        return (int)$this->executeInsertInternal($sql, $bind);
    }

    public function executeUpdate(string $sql, array $bind = []): int
    {
        return $this->execute(DbUpdating::class, DbUpdated::class, $sql, $bind);
    }

    public function executeDelete(string $sql, array $bind = []): int
    {
        return $this->execute(DbDeleting::class, DbDeleted::class, $sql, $bind);
    }

    public function fetchAll(string $sql, array $bind = []): array
    {
        $connection = $this->getReadConnection();

        $sql = $connection->convertQuotes($sql);

        $this->eventDispatcher->dispatch(new DbQuerying($this, $sql, $bind));

        $startTime = $this->clock->microtime();
        $result = $connection->query($sql, $bind, PDO::FETCH_ASSOC);
        $elapsed = $this->clock->elapsed($startTime);

        $count = count($result);
        $this->eventDispatcher->dispatch(new DbQueried($this, $sql, $bind, $count, $result, $elapsed));

        return $result;
    }

    /**
     * Wrap table name with prefix and bracket notation.
     *
     * Prepends the configured table prefix and wraps the table name in brackets
     * for cross-database compatibility. Handles schema.table notation.
     *
     * @param string $table Table name (may include schema: 'schema.table')
     *
     * @return string Wrapped table name (e.g., '[prefix_table]' or '[schema].[prefix_table]')
     */
    protected function wrapTableName(string $table): string
    {
        if (($pos = strpos($table, '.')) === false) {
            return '[' . $this->prefix . $table . ']';
        } else {
            return '[' . substr($table, 0, $pos) . '].[' . $this->prefix . substr($table, $pos + 1) . ']';
        }
    }

    public function insert(string $table, array $record, bool $fetchInsertId = false): int|string
    {
        $table = $this->wrapTableName($table);

        if (!$record) {
            return 0;
        }
        $fields = array_keys($record);
        $insertedValues = ':' . implode(',:', $fields);
        $insertedFields = '[' . implode('],[', $fields) . ']';

        $sql
            = /** @lang text */
            "INSERT INTO $table ($insertedFields) VALUES ($insertedValues)";

        return $this->executeInsertInternal($sql, $record, $fetchInsertId);
    }

    /**
     * Inserts multiple records using one INSERT statement.
     *
     * All records should share the same field keys.
     *
     * @param string $table Table name (prefix will be automatically applied)
     * @param array<int, array<string, mixed>> $records Array of records to insert, each record is an associative array
     *
     * @return int Number of affected rows (typically equal to count of records); returns 0 when $records is empty
     */
    public function bulkInsert(string $table, array $records): int
    {
        $table = $this->wrapTableName($table);

        if (empty($records)) {
            return 0;
        }

        $firstRecord = array_first($records);
        $fields = array_keys($firstRecord);
        $insertedFields = '[' . implode('],[', $fields) . ']';

        // Build bulk VALUES clause
        $valueGroups = [];
        $bindParams = [];

        foreach ($records as $i => $record) {
            $placeholders = [];
            foreach ($fields as $field) {
                $placeholder = $field . '_' . $i;
                $placeholders[] = ':' . $placeholder;
                $bindParams[$placeholder] = $record[$field] ?? null;
            }
            $valueGroups[] = '(' . implode(',', $placeholders) . ')';
        }

        $sql = /** @lang text */
            "INSERT INTO $table ($insertedFields) VALUES " . implode(',', $valueGroups);

        return (int)$this->executeInsertInternal($sql, $bindParams);
    }

    public function insertBySql(string $table, string $sql, array $bind = []): int
    {
        $table = $this->wrapTableName($table);

        return $this->executeInsert(/**@lang text */ "INSERT INTO $table $sql", $bind);
    }

    public function update(string $table, array $fieldValues, string|array $conditions, array $bind = []): int
    {
        $table = $this->wrapTableName($table);

        if (!$fieldValues) {
            return 0;
        }

        if (!$conditions) {
            UnsafeOperationException::raise('UPDATE on "{table}" rejected: WHERE clause is required to prevent accidental full-table update', ['table' => $table]);
        }

        $wheres = [];

        foreach ((array)$conditions as $key => $condition) {
            if (is_int($key)) {
                if (!is_string($condition) || $condition === '' || preg_match('#^\w+$#', $condition) === 1) {
                    UnsafeOperationException::raise('UPDATE on "{table}" rejected: condition must be a valid SQL expression, not empty or single word', ['table' => $table]);
                }
                $wheres[] = stripos($condition, ' or ') !== false ? "($condition)" : $condition;
            } else {
                $wheres[] = "[$key]=:$key";
                $bind[$key] = $condition;
            }
        }

        $setFields = [];
        foreach ($fieldValues as $field => $value) {
            if (is_int($field)) {
                $setFields[] = $value;
            } elseif ($value instanceof SqlFragmentInterface) {
                $value->setField($field);
                $setFields[] = $value->getExpression();
                $fragmentBind = $value->getBind();
                if (!empty($fragmentBind)) {
                    foreach ($fragmentBind as $bindKey => $bindValue) {
                        $bind[$bindKey] = $bindValue;
                    }
                }
            } else {
                $setFields[] = "[$field]=:$field";
                $bind[$field] = $value;
            }
        }

        $sql
            = /**@lang text */
            "UPDATE $table SET " . implode(',', $setFields) . ' WHERE ' . implode(' AND ', $wheres);

        return $this->executeUpdate($sql, $bind);
    }

    public function updateBySql(string $table, string $sql, array $bind = []): int
    {
        $table = $this->wrapTableName($table);

        return $this->executeUpdate(/** @lang text */ "UPDATE $table SET $sql", $bind);
    }

    public function upsert(
        string  $table,
        array   $insertFieldValues,
        array   $updateFieldValues = [],
        ?string $primaryKey = null
    ): int {
        if (!$primaryKey) {
            $primaryKey = (string)key($insertFieldValues);
        }

        if (!$updateFieldValues) {
            $updateFieldValues = $insertFieldValues;
        }

        // Process SqlFragmentInterface objects before passing to connection
        $processedUpdateValues = [];
        foreach ($updateFieldValues as $key => $value) {
            if ($value instanceof SqlFragmentInterface) {
                $field = (string) $key;
                $value->setField($field);
                $processedUpdateValues[$field] = new UpsertExpression($value->getExpression());
                $fragmentBind = $value->getBind();
                if (!empty($fragmentBind)) {
                    foreach ($fragmentBind as $bindKey => $bindValue) {
                        if (array_key_exists($bindKey, $insertFieldValues) && $insertFieldValues[$bindKey] !== $bindValue) {
                            DbException::raise('Upsert bind parameter "{param}" conflicts between insert and update values', ['param' => $bindKey]);
                        }
                        $insertFieldValues[$bindKey] = $bindValue;
                    }
                }
            } else {
                $processedUpdateValues[$key] = $value;
            }
        }

        $table = $this->wrapTableName($table);

        $this->eventDispatcher->dispatch(new DbUpserting($this, $table, $insertFieldValues, $processedUpdateValues, $primaryKey));

        $connection = $this->getWriteConnection();

        $startTime = $this->clock->microtime();
        $count = $connection->upsert($table, $insertFieldValues, $processedUpdateValues, $primaryKey);
        $elapsed = $this->clock->elapsed($startTime);

        $this->eventDispatcher->dispatch(new DbUpserted($this, $table, $insertFieldValues, $processedUpdateValues, $primaryKey, $count, $elapsed));

        return $count;
    }

    public function delete(string $table, string|array $conditions, array $bind = []): int
    {
        $table = $this->wrapTableName($table);

        if (!$conditions) {
            UnsafeOperationException::raise('DELETE on "{table}" rejected: WHERE clause is required to prevent accidental full-table deletion', ['table' => $table]);
        }

        $wheres = [];
        foreach ((array)$conditions as $key => $condition) {
            if (is_int($key)) {
                // Safety check: reject unsafe raw SQL conditions
                // - Must be a non-empty string
                // - Allow special keyword FALSE (for WHERE FALSE pattern)
                // - Allow complex expressions (with operators/spaces)
                // - Reject simple identifiers (single words, likely mistakes)
                $isSimpleIdentifier = is_string($condition) && preg_match('#^\w+$#', $condition) === 1;

                if (!is_string($condition) || $condition === '' || ($isSimpleIdentifier && $condition !== self::SQL_FALSE_KEYWORD)) {
                    UnsafeOperationException::raise('DELETE on "{table}" rejected: condition must be a valid SQL expression, not empty or single word', ['table' => $table]);
                }
                $wheres[] = stripos($condition, ' or ') !== false ? "($condition)" : $condition;
            } else {
                $wheres[] = "[$key]=:$key";
                $bind[$key] = $condition;
            }
        }

        $sql
            = /** @lang text */
            "DELETE FROM $table WHERE " . implode(' AND ', $wheres);
        return $this->executeDelete($sql, $bind);
    }

    public function deleteBySql(string $table, string $sql, array $bind = []): int
    {
        $table = $this->wrapTableName($table);

        return $this->executeDelete(/**@lang text */ "DELETE FROM $table WHERE $sql", $bind);
    }

    public function begin(): void
    {
        if (!$this->guard) {
            TransientClientException::raise('Cannot call begin(): this client is not transient. Call getTransient() first to get a connection-pinned client.');
        }

        if ($this->transaction_level === 0) {
            $this->eventDispatcher->dispatch(new DbBegin($this));

            $connection = $this->connection ?? $this->guard->resource;

            try {
                $connection->begin();
                $this->connection = $connection;
                $this->transaction_level++;
            } catch (PDOException $exception) {
                $this->connection = null;

                $message = 'Failed to begin database transaction: ' . $exception->getMessage();
                DbException::raise($message, [], $exception->getCode(), $exception);
            }
        } else {
            $this->transaction_level++;
        }
    }

    public function inTransaction(): bool
    {
        return $this->transaction_level !== 0;
    }

    public function rollback(): void
    {
        if ($this->transaction_level === 0) {
            return;
        }

        if (!$this->guard) {
            TransientClientException::raise('Cannot call rollback(): this client is not transient. Call getTransient() first to get a connection-pinned client.');
        }

        $this->transaction_level--;

        if ($this->transaction_level === 0) {
            try {
                $this->connection?->rollback();
            } catch (PDOException $exception) {
                $message = 'Failed to rollback database transaction: ' . $exception->getMessage();
                DbException::raise($message, [], $exception->getCode(), $exception);
            } finally {
                $this->connection = null;
                $this->eventDispatcher->dispatch(new DbRollback($this));
            }
        }
    }

    public function commit(): void
    {
        if ($this->transaction_level === 0) {
            NoActiveTransactionException::raise('No active transaction to commit, call begin() first');
        }

        if (!$this->guard) {
            TransientClientException::raise('Cannot call commit(): this client is not transient. Call getTransient() first to get a connection-pinned client.');
        }

        $this->transaction_level--;

        if ($this->transaction_level === 0) {
            try {
                $this->connection?->commit();
            } catch (PDOException $exception) {
                DbException::raise('Failed to commit database transaction: {message}', ['message' => $exception->getMessage()], $exception->getCode(), $exception);
            } finally {
                $this->connection = null;
                $this->eventDispatcher->dispatch(new DbCommit($this));
            }
        }
    }

    public function getTables(?string $schema = null): array
    {
        $connection = $this->getReadConnection();

        if ($this->prefix === '') {
            return $connection->getTables($schema);
        }

        $prefix = $this->prefix;
        $prefixLen = strlen($prefix);
        $tables = [];
        foreach ($connection->getTables($schema) as $table) {
            if (str_starts_with($table, $prefix)) {
                $tables[] = substr($table, $prefixLen);
            }
        }
        return $tables;
    }

    public function buildSql(array $params): string
    {
        return $this->getReadConnection()->buildSql($params);
    }

    /**
     * @return array{
     *     0: array<string, mixed>,
     *     1: list<string>,
     *     2: mixed|null,
     *     3: list<string>
     * }
     */
    public function getMetadata(string $table): array
    {
        $table = $this->wrapTableName($table);

        $connection = $this->getReadConnection();

        $startTime = $this->clock->microtime();
        $meta = $connection->getMetadata($table);
        $elapsed = $this->clock->elapsed($startTime);

        $this->eventDispatcher->dispatch(new DbMetadata($this, $table, $meta, $elapsed));

        return $meta;
    }

    public function close(): void
    {
        if ($this->connection) {
            if ($this->transaction_level !== 0) {
                $this->transaction_level = 0;
                try {
                    $this->connection->rollback();
                } finally {
                    if (!$this->guard) {
                        $this->poolManager->release($this, $this->connection);
                    }
                }
            }
            $this->connection = null;
        }
    }

    /**
     * Allocates an inclusive ID range <code>[startId, endId]</code> for batch insert.
     *
     * Uses a separate master connection. Must not run inside an active transaction.
     *
     * @param string $table Table name (without prefix - prefix will be automatically applied)
     * @param string $primaryKey Primary key column name (for sequence detection in PostgreSQL)
     * @param int $count Number of IDs to allocate
     *
     * @return array{int, int} Array with [startId, endId] (both inclusive)
     *
     * @throws \Switon\Db\Exception If concurrent modification detected
     * @throws \Switon\Db\Exception On database errors or if table doesn't exist
     */
    public function allocateIds(string $table, string $primaryKey, int $count): array
    {
        // Check transaction state before allocating IDs
        // DDL statements (ALTER TABLE, DBCC CHECKIDENT, etc.) cause implicit commits in most databases,
        // which would unexpectedly commit the outer transaction
        if ($this->inTransaction()) {
            Exception::raise(
                'Cannot call allocateIds() within a transaction. ' .
                'ID allocation uses DDL statements (ALTER TABLE, DBCC CHECKIDENT, etc.) that cause implicit commits ' .
                'in most databases, which would commit the outer transaction unexpectedly. ' .
                'Call allocateIds() before starting the transaction, or use it outside of transactional operations.'
            );
        }

        $owner = $this->guard->parent ?? $this;
        $connection = $this->poolManager->guard($owner, $this->timeout);

        // Pass table name with prefix to connection layer
        // Connection layer works with actual database table names
        $tableWithPrefix = $this->prefix . $table;

        return $connection->allocateIds($tableWithPrefix, $primaryKey, $count);
    }
}
