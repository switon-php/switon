<?php

declare(strict_types=1);

namespace Switon\Db;

use JsonSerializable;
use PDO;
use PDOException;
use PDOStatement;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Json;
use Switon\Core\MakerInterface;
use Switon\Db\Event\DbAbnormal;
use Switon\Db\Event\DbClose;
use Switon\Db\Event\DbConnected;
use Switon\Db\Event\DbConnecting;
use Switon\Db\Exception as DbException;
use Switon\Db\Exception\ConnectionException;
use Switon\Db\Exception\ReadonlyException;
use Switon\Db\Exception\UnsupportedParameterTypeException;
use Throwable;

use function array_keys;
use function array_shift;
use function count;
use function explode;
use function gettype;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_scalar;
use function is_string;
use function microtime;
use function str_starts_with;

/**
 * Base PDO-backed connection for DB adapters.
 *
 * Provides lazy connection, statement caching, retry-once behavior, and DB events.
 *
 * Guidance: Keep adapter-specific behavior in subclasses; use this base for shared PDO execution, retry, and bind normalization only.
 *
 * Road-signs:
 * - getPdo() opens the lazy connection and dispatches connect events
 * - execute()/query() normalize binds and reuse prepared statements
 * - convertQuotes() stays adapter-specific in subclasses
 * - upsert() falls back to SELECT plus INSERT/UPDATE when no native syntax exists
 * - close() resets transaction and prepared-statement state
 *
 * @see \Switon\Db\ConnectionInterface
 * @see \Switon\Db\Event\DbConnecting
 * @see \Switon\Db\Event\DbConnected
 * @see \Switon\Db\Exception\ConnectionException
 */
abstract class AbstractConnection implements ConnectionInterface
{
    /** Maximum number of cached prepared statements per connection instance (small LRU cap). */
    protected const int MAX_PREPARED_STATEMENTS = 8;

    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected MakerInterface $maker;

    /** Connection URI injected from DB configuration. */
    #[Autowired] protected string $uri;

    protected string $dsn;
    protected string $username;
    protected string $password;
    protected bool $emulate_prepares = false;
    /** @var array<int|string, mixed> PDO connection options */
    protected array $options = [];
    protected ?PDO $pdo = null;
    protected bool $in_transaction = false;
    /** @var array<string, PDOStatement> Prepared statement cache */
    protected array $prepared = [];
    protected bool $readonly = false;
    protected ?float $last_heartbeat = null;

    public function __construct()
    {
        $this->options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $this->options[PDO::ATTR_EMULATE_PREPARES] = $this->emulate_prepares;
    }

    public function __clone()
    {
        $this->pdo = null;
        $this->in_transaction = false;
        $this->last_heartbeat = null;
        $this->prepared = [];
    }

    /** Detects the internal value object used for explicit raw upsert expressions. */
    protected function isUpsertExpression(mixed $value): bool
    {
        return $value instanceof UpsertExpression;
    }

    /** Returns the raw SQL expression stored in the internal upsert value object. */
    protected function getUpsertExpression(UpsertExpression $value): string
    {
        return $value->sql;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * {@inheritDoc}
     */
    public function getVersion(): ?string
    {
        try {
            $v = $this->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
            return is_string($v) && $v !== '' ? $v : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function ping(): bool
    {
        try {
            // Ensure we are checking a real, active connection.
            // If no PDO instance exists yet, attempt to establish one via getPdo().
            $pdo = $this->pdo ?? $this->getPdo();
            $pdo->query("SELECT 'PING'")?->fetchAll();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Returns an active PDO instance, creating it on first use.
     *
     * @throws \Switon\Db\Exception\ConnectionException
     * @throws PDOException
     */
    protected function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = $this->dsn;
            $uri = $this->uri;

            $this->eventDispatcher->dispatch(new DbConnecting($this, $dsn, $uri));

            try {
                $params = [$dsn, $this->username, $this->password, $this->options];
                $this->pdo = $pdo = $this->maker->make(PDO::class, $params);
            } catch (PDOException $e) {
                $code = $e->getCode();
                ConnectionException::raise('Database connection failed for "{dsn}": {error}', ['dsn' => $dsn, 'error' => $e->getMessage()], $code, $e);
            }

            $this->eventDispatcher->dispatch(new DbConnected($this, $dsn, $uri, $pdo));
        }

        return $this->pdo;
    }

    /** Escapes identifiers with bracket notation (supports dotted names). */
    protected function escapeIdentifier(string $identifier): string
    {
        $list = [];
        foreach (explode('.', $identifier) as $id) {
            $list[] = str_starts_with($id, '[') ? $id : "[$id]";
        }

        return implode('.', $list);
    }

    /**
     * Returns a cached prepared statement (LRU-evicted when cache is full).
     *
     * @throws PDOException
     */
    protected function getPrepared(string $sql): PDOStatement
    {
        if (!isset($this->prepared[$sql])) {
            if (count($this->prepared) >= self::MAX_PREPARED_STATEMENTS) {
                array_shift($this->prepared);
            }
            return $this->prepared[$sql] = $this->getPdo()->prepare($sql);
        }
        return $this->prepared[$sql];
    }

    /**
     * Executes a prepared statement with normalized bind values.
     *
     * Arrays and JsonSerializable values are JSON-encoded before binding.
     *
     * @param array<string|int, mixed> $bind
     *
     * @throws \Switon\Db\Exception\UnsupportedParameterTypeException
     * @throws PDOException
     */
    protected function executeInternal(string $sql, array $bind): PDOStatement
    {
        $statement = $this->getPrepared($sql);

        $tr = [];
        foreach ($bind as $parameter => $value) {
            // Convert value based on type
            $value = match (true) {
                is_bool($value) => (int)$value,
                is_scalar($value) || $value === null => $value,
                is_array($value) => Json::stringify($value),
                $value instanceof JsonSerializable => Json::stringify($value),
                default => UnsupportedParameterTypeException::raise('Cannot bind parameter of type {type}: only scalar, null, array, and JsonSerializable are supported', ['type' => gettype($value)])
            };

            // Handle parameter naming (positional vs named)
            if (is_int($parameter)) {
                $tr[$parameter + 1] = $value;
            } else {
                $tr[str_starts_with($parameter, ':') ? $parameter : ':' . $parameter] = $value;
            }
        }

        $statement->execute($tr);

        $this->last_heartbeat = microtime(true);

        return $statement;
    }

    public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
    {
        if ($this->readonly) {
            ReadonlyException::raise('Cannot execute write operation on readonly connection "{uri}"', ['uri' => $this->uri]);
        }

        $sql = $this->convertQuotes($sql);

        if ($this->in_transaction) {
            try {
                $rows = $bind ? $this->executeInternal($sql, $bind)->rowCount() : $this->getPdo()->exec($sql);
                return $has_insert_id ? (int)$this->getPdo()->lastInsertId() : $rows;
            } catch (PDOException $exception) {
                DbException::raise($exception, ['sql' => $sql, 'bind' => $bind]);
            }
        }

        try {
            $rows = $bind ? $this->executeInternal($sql, $bind)->rowCount() : $this->getPdo()->exec($sql);
            return $has_insert_id ? (int)$this->getPdo()->lastInsertId() : $rows;
        } catch (PDOException $firstException) {
            try {
                $this->close();
                $rows = $bind ? $this->executeInternal($sql, $bind)->rowCount() : $this->getPdo()->exec($sql);
                return $has_insert_id ? (int)$this->getPdo()->lastInsertId() : $rows;
            } catch (PDOException $retryException) {
                DbException::raise($retryException, ['sql' => $sql, 'bind' => $bind]);
            }
        }
    }

    public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
    {
        $sql = $this->convertQuotes($sql);

        if ($this->in_transaction) {
            try {
                $statement = $bind ? $this->executeInternal($sql, $bind) : $this->getPdo()->query($sql);
                return $statement->fetchAll($mode);
            } catch (PDOException $exception) {
                DbException::raise($exception, ['sql' => $sql, 'bind' => $bind]);
            }
        }

        try {
            $statement = $bind ? $this->executeInternal($sql, $bind) : $this->getPdo()->query($sql);
            return $statement->fetchAll($mode);
        } catch (PDOException $firstException) {
            try {
                $this->close();
                $statement = $bind ? $this->executeInternal($sql, $bind) : $this->getPdo()->query($sql);
                return $statement->fetchAll($mode);
            } catch (PDOException $retryException) {
                DbException::raise($retryException, ['sql' => $sql, 'bind' => $bind]);
            }
        }
    }

    public function close(): void
    {
        if ($this->pdo) {
            $dsn = $this->dsn;
            $uri = $this->uri;
            $pdo = $this->pdo;
            $this->eventDispatcher->dispatch(new DbClose($this, $dsn, $uri, $pdo));

            if ($this->in_transaction) {
                $this->in_transaction = false;
                $this->eventDispatcher->dispatch(new DbAbnormal($this, $dsn, $uri, $pdo));
            }

            $this->pdo = null;
            $this->last_heartbeat = null;
            $this->prepared = [];
        }
    }

    /** Returns microtime of the last successful DB operation. */
    public function getLastHeartbeat(): ?float
    {
        return $this->last_heartbeat;
    }

    public function begin(): void
    {
        if ($this->readonly) {
            ReadonlyException::raise('Cannot begin transaction on readonly connection "{uri}"', ['uri' => $this->uri]);
        }

        try {
            $this->in_transaction = $this->getPdo()->beginTransaction();
        } catch (PDOException $firstException) {
            try {
                $this->close();
                $this->in_transaction = $this->getPdo()->beginTransaction();
            } catch (PDOException $retryException) {
                DbException::raise($retryException);
            }
        }
    }

    public function rollback(): void
    {
        $this->in_transaction = false;
        $this->pdo?->rollBack();
    }

    public function commit(): void
    {
        $this->in_transaction = false;
        $this->pdo?->commit();
    }

    public function isInTransaction(): bool
    {
        return $this->in_transaction;
    }

    /**
     * Fallback upsert (SELECT + INSERT/UPDATE), intended for adapters without native upsert.
     *
     * This implementation is not atomic under concurrent writes; competing writers can both pass
     * the existence check and then race on INSERT (duplicate-row or duplicate-key outcomes).
     */
    public function upsert(string $table, array $insertFieldValues, array $updateFieldValues, string $primaryKey): int
    {
        $table = $this->convertQuotes($table);

        // Check if record exists
        $checkSql = "SELECT 1 FROM $table WHERE " . $this->convertQuotes("[$primaryKey]") . "=:pk LIMIT 1";
        $exists = !empty($this->query($checkSql, ['pk' => $insertFieldValues[$primaryKey]]));

        if ($exists) {
            // UPDATE existing record
            $updates = [];
            $bind = [];
            foreach ($updateFieldValues as $key => $value) {
                $field = is_string($key) ? $key : $value;
                if ($primaryKey === $field) {
                    continue;
                }

                if (is_int($key)) {
                    // Integer key: field name is the value, get value from insertFieldValues
                    $updates[] = $this->convertQuotes("[$field]") . "=:{$field}_upd";
                    $bind["{$field}_upd"] = $insertFieldValues[$field];
                } elseif ($this->isUpsertExpression($value)) {
                    $updates[] = $this->convertQuotes($this->getUpsertExpression($value));
                } else {
                    $updates[] = $this->convertQuotes("[$key]") . "=:{$key}_upd";
                    $bind["{$key}_upd"] = $value;
                }
            }

            $whereSql = $this->convertQuotes("[$primaryKey]") . '=:pk';
            $bind['pk'] = $insertFieldValues[$primaryKey];

            $sql = "UPDATE $table SET " . implode(',', $updates) . " WHERE $whereSql";
            return $this->execute($sql, $bind);
        } else {
            // INSERT new record
            $fields = array_keys($insertFieldValues);
            $insertFields = $this->convertQuotes('[' . implode('],[', $fields) . ']');
            $insertValues = ':' . implode(',:', $fields);

            $sql = "INSERT INTO $table ($insertFields) VALUES ($insertValues)";
            return $this->execute($sql, $insertFieldValues);
        }
    }

    /** Builds a SELECT SQL string from normalized fragment parts in <code>$params</code>. */
    public function buildSql(array $params): string
    {
        $sql = '';

        if (isset($params['fields'])) {
            $sql .= 'SELECT ';

            if (isset($params['distinct'])) {
                $sql .= 'DISTINCT ';
            }

            $sql .= $params['fields'];
        }

        if (isset($params['from'])) {
            $sql .= ' FROM ' . $params['from'];
        }

        if (isset($params['join'])) {
            $sql .= $params['join'];
        }

        if (isset($params['where'])) {
            $sql .= ' WHERE ' . $params['where'];
        }

        if (isset($params['group'])) {
            $sql .= ' GROUP BY ' . $params['group'];
        }

        if (isset($params['having'])) {
            $sql .= ' HAVING ' . $params['having'];
        }

        if (isset($params['order'])) {
            $sql .= ' ORDER BY ' . $params['order'];
        }

        if (isset($params['limit'])) {
            $sql .= ' LIMIT ' . $params['limit'];
        }

        if (isset($params['offset'])) {
            $sql .= ' OFFSET ' . $params['offset'];
        }

        if (isset($params['forUpdate'])) {
            $sql .= ' FOR UPDATE';
        }

        return $sql;
    }
}
