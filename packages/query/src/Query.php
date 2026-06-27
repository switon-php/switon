<?php

declare(strict_types=1);

namespace Switon\Query;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\InvalidValueException;
use Switon\Db\ClientInterface;
use Switon\Di\NamedLookupInterface;
use Switon\Query\Event\QuerySkipped;
use Switon\Sharding\Exception\ShardingNotSupportedException;
use Switon\Sharding\Exception\ShardingTooManyException;
use Throwable;

use function array_key_exists;
use function array_merge;
use function array_slice;
use function count;
use function current;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function key;
use function preg_match;
use function preg_replace;
use function rsort;
use function sort;
use function strpbrk;
use function strtoupper;
use function substr;

/**
 * Default SQL implementation of <code>QueryInterface</code>.
 *
 * Use when you need SQL generation with bind parameters, joins, and sharding-aware execution.
 * Emits <code>\Switon\Query\Event\QuerySkipped</code> when execution is skipped by impossible conditions.
 *
 * Road-signs:
 * - PredicatePipeline parse/compile
 * - joins via Table + condition
 * - shard merge <code>ShardMergerInterface</code>
 * - emits <code>QuerySkipped</code> on impossible filters
 * - fail ShardingNotSupported/TooMany
 *
 * @see \Switon\Query\AbstractQuery
 * @see \Switon\Query\QueryInterface
 * @see \Switon\Query\QueryInterface::execute()
 * @see \Switon\Query\Exception
 * @see \Switon\Query\Table
 * @see \Switon\Query\PredicatePipelineInterface
 * @see \Switon\Query\Paginator
 * @see \Switon\Query\ShardMergerInterface
 * @see \Switon\Di\NamedLookupInterface
 * @see \Switon\Sharding\Exception\ShardingNotSupportedException
 * @see \Switon\Query\Event\QuerySkipped
 * @see \Switon\Db\ClientInterface
 */
class Query extends AbstractQuery
{
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    /** @var NamedLookupInterface<object> */
    #[Autowired] protected NamedLookupInterface $namedLookup;
    #[Autowired] protected ShardMergerInterface $shardMerger;
    #[Autowired] protected SqlBuilderInterface $sqlBuilder;

    /** @var string|null Cached SQL string. */
    protected ?string $sql = null;

    public function __clone()
    {
        $this->clauses = clone $this->clauses;
    }

    /**
     * @param list<array{0: Table|string, 1: string|null, 2: string|null, 3: string|null}> $joinsForSql
     */
    protected function sqlClausesForCompile(array $joinsForSql): Clauses
    {
        $compiled = $this->predicatePipeline->compile($this->clauses->predicates);
        $this->clauses->conditions = $compiled['conditions'];
        $this->clauses->bind = array_merge($compiled['bind'], $this->clauses->extraBind);

        $out = clone $this->clauses;
        $out->joins = $joinsForSql;

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function select(array $fields): static
    {
        if (!$fields) {
            return $this;
        }

        $r = $this->clauses->fields ? $this->clauses->fields . ', ' : '';
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                if (is_string($k)) {
                    $this->with([$k => $v]);
                }
            } else {
                if (!is_string($v)) {
                    InvalidValueException::raise('select() field at index {key} must be string, got {type}', ['type' => gettype($v), 'key' => $k]);
                }
                if (strpbrk($v, '[(') === false) {
                    $r .= is_int($k) ? preg_replace('#\w+#', '[\\0]', $v) . ', ' : preg_replace('#\w+#', '[\\0]', $v) . ' AS [' . $k . '], ';
                } else {
                    $r .= is_int($k) ? $v . ', ' : $v . ' AS [' . $k . '], ';
                }
            }
        }
        if ($r) {
            $this->clauses->fields = substr($r, 0, -2);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function join(Table $table, ?string $condition = null, ?string $alias = null, ?string $type = null): static
    {
        if ($condition !== null && strpbrk($condition, '[(') === false) {
            $condition = preg_replace('#\w+#', '[\\0]', $condition);
        }

        $this->clauses->joins[] = [$table, $condition, $alias, $type];

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function innerJoin(Table $table, ?string $condition = null, ?string $alias = null): static
    {
        return $this->join($table, $condition, $alias, 'INNER');
    }

    /**
     * {@inheritDoc}
     */
    public function leftJoin(Table $table, ?string $condition = null, ?string $alias = null): static
    {
        return $this->join($table, $condition, $alias, 'LEFT');
    }

    /**
     * {@inheritDoc}
     */
    public function rightJoin(Table $table, ?string $condition = null, ?string $alias = null): static
    {
        return $this->join($table, $condition, $alias, 'RIGHT');
    }

    /**
     * Gets joins for sharding resolution.
     *
     * @return list<array{0: Table|string, 1: string|null, 2: string|null, 3: string|null}> Join definitions
     */
    protected function getJoins(): array
    {
        return $this->clauses->joins;
    }

    /**
     * {@inheritDoc}
     */
    public function having(string|array $having, array $bind = []): static
    {
        if (is_array($having)) {
            if (count($having) === 1) {
                $this->clauses->having = $having[0];
            } else {
                $items = [];
                foreach ($having as $item) {
                    $items[] = '(' . $item . ')';
                }
                $this->clauses->having = implode(' AND ', $items);
            }
        } else {
            $this->clauses->having = $having;
        }

        if ($bind) {
            $this->setBind($bind);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function forUpdate(bool $forUpdate = true): static
    {
        $this->clauses->forUpdate = $forUpdate;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getSql(): string
    {
        if ($this->sql === null) {
            $shards = $this->getShards();

            $tables = current($shards);
            if (count($tables) !== 1) {
                ShardingTooManyException::raise('Query spans {count} tables ({tables}), single table required for this operation', ['count' => count($tables), 'tables' => implode(', ', $tables)]);
            }

            $connection = key($shards);
            $db = $this->namedLookup->by(ClientInterface::class, $connection);

            $joinRows = [];
            foreach ($this->clauses->joins as $join) {
                $jt = $join[0];
                $joinRows[] = [
                    $jt instanceof Table ? $jt->table : $jt,
                    $join[1],
                    $join[2],
                    $join[3],
                ];
            }
            $this->sql = $this->sqlBuilder->buildSql($db, $tables[0], $this->sqlClausesForCompile($joinRows));
        }

        return $this->sql;
    }

    /**
     * {@inheritDoc}
     */
    public function getBind(?string $key = null): array
    {
        $compiled = $this->predicatePipeline->compile($this->clauses->predicates);
        $bind = array_merge($compiled['bind'], $this->clauses->extraBind);
        if ($key !== null) {
            return [$key => ($bind[$key] ?? null)];
        }
        return $bind;
    }

    /**
     * {@inheritDoc}
     */
    public function setBind(array $bind, bool $merge = true): static
    {
        $this->clauses->extraBind = $merge ? array_merge($this->clauses->extraBind, $bind) : $bind;

        return $this;
    }

    /**
     * Executes query on a single shard.
     *
     * @param Table|string $connectionOrTable Table or connection name
     * @param string|null $table Table name (required if first param is connection)
     *
     * @return list<array<string, mixed>> Query results
     */
    protected function query(Table|string $connectionOrTable, ?string $table = null): array
    {
        // Support both Table and legacy [connection, table] format for backward compatibility
        if ($connectionOrTable instanceof Table) {
            $connection = $connectionOrTable->connection;
            $table = $connectionOrTable->table;
        } else {
            if ($table === null) {
                InvalidValueException::raise('Table name is required when connection is provided as string');
            }
            $connection = $connectionOrTable;
        }

        $db = $this->namedLookup->by(ClientInterface::class, $connection);

        $joins = [];
        if ($this->clauses->joins) {
            // Only supports sharding based on main table: join tables use the same shard index as main table
            // Extract shard suffix from main table shard name (e.g., 'user_0' -> '_0')
            $shardSuffix = '';
            if (preg_match('#(_\d+)$#', $table, $match)) {
                $shardSuffix = $match[1];
            }

            foreach ($this->clauses->joins as $join) {
                /** @var Table $joinTable */
                $joinTable = $join[0];
                $join_table_name = $joinTable->table;

                // Apply main table's shard suffix to join table
                // Validation already done in getShards()
                $join[0] = $join_table_name . $shardSuffix;
                $joins[] = $join;
            }
        }

        $this->sql = $this->sqlBuilder->buildSql($db, $table, $this->sqlClausesForCompile($joins));

        $rows = $db->fetchAll($this->sql, $this->clauses->bind);

        // Convert column names back to property names using columnMap
        if ($this->clauses->columnMap !== null && $this->clauses->columnMap !== []) {
            foreach ($rows as &$row) {
                foreach ($this->clauses->columnMap as $property => $column) {
                    if (array_key_exists($column, $row)) {
                        $row[$property] = $row[$column];
                        unset($row[$column]);
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * {@inheritDoc}
     *
     * Dispatches <code>QuerySkipped</code> when conditions are unsatisfiable
     * (for example, <code>whereIn('id', [])</code>) so log listeners can record
     * the skipped query.
     */
    public function execute(): array
    {
        $compiled = $this->predicatePipeline->compile($this->clauses->predicates);
        $conditions = $compiled['conditions'];
        if (in_array('FALSE', $conditions, true)) {
            $bind = array_merge($compiled['bind'], $this->clauses->extraBind);
            $this->eventDispatcher->dispatch(new QuerySkipped($this, $this->sql ?? '', $bind));
            return [];
        }

        $shards = $this->getShards();

        $result = [];
        if (!empty($shards) && count($shards) === 1 && count(current($shards)) === 1) {
            $result = $this->query(Table::of(current($shards)[0], key($shards)));
        } elseif ($this->clauses->order) {
            $copy = clone $this;

            if ($copy->clauses->limit) {
                $copy->clauses->limit += $copy->clauses->offset;
                $copy->clauses->offset = 0;
            }

            $valid_times = 0;
            foreach ($shards as $connection => $tables) {
                foreach ($tables as $table) {
                    if ($r = $copy->query(Table::of($table, $connection))) {
                        $valid_times++;
                        $result = $result ? array_merge($result, $r) : $r;
                    }
                }
            }

            if ($valid_times > 1) {
                $result = $this->shardMerger->sort($result, $this->clauses->order);
            }

            $result = $this->clauses->limit ? array_slice($result, $this->clauses->offset, $this->clauses->limit) : $result;
        } elseif ($this->clauses->limit) {
            foreach ($shards as $connection => $tables) {
                foreach ($tables as $table) {
                    if ($r = $this->query(Table::of($table, $connection))) {
                        $result = $result ? array_merge($result, $r) : $r;
                        if (count($result) >= $this->clauses->offset + $this->clauses->limit) {
                            $result = array_slice($result, $this->clauses->offset, $this->clauses->limit);
                            return $this->index ? $this->shardMerger->indexBy($result, $this->index) : $result;
                        }
                    }
                }
            }

            $result = $result ? array_slice($result, $this->clauses->offset, $this->clauses->limit) : [];
        } else {
            foreach ($shards as $connection => $tables) {
                foreach ($tables as $table) {
                    if ($r = $this->query(Table::of($table, $connection))) {
                        $result = $result ? array_merge($result, $r) : $r;
                    }
                }
            }
        }

        return $this->index ? $this->shardMerger->indexBy($result, $this->index) : $result;
    }

    /**
     * Builds aggregate fields clause.
     *
     * @param array<int|string, string> $expr Aggregate expressions
     * @param list<string> $group GROUP BY fields
     *
     * @return string SQL aggregate fields clause
     */
    protected function buildAggregate(array $expr, array $group): string
    {
        $fields = '';

        foreach ($expr as $k => $v) {
            if (is_int($k)) {
                $fields .= '[' . $v . '], ';
            } else {
                if (preg_match('#^(\w+)\((\w+)\)$#', $v, $matches) === 1) {
                    $fields .= strtoupper($matches[1]) . '([' . $matches[2] . '])';
                } else {
                    $fields .= $v;
                }

                $fields .= ' AS [' . $k . '], ';
            }
        }

        if ($group) {
            foreach ($group as $k => $v) {
                $fields .= is_int($k) ? "[$v], " : "$v, ";
            }
        }

        return substr($fields, 0, -2);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int|string, string> $expr
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function aggregate(array $expr): array
    {
        $this->aggregate = $expr;

        $shards = $this->getShards();

        if (count($shards) === 1 && count(current($shards)) === 1) {
            $this->clauses->fields = $this->buildAggregate($expr, $this->clauses->group ?? []);

            $result = $this->query(Table::of(current($shards)[0], key($shards)));
        } else {
            if ($this->clauses->having) {
                ShardingNotSupportedException::raise('HAVING clause is not supported in sharded queries');
            }

            $aggs = [];
            foreach ($this->aggregate as $k => $v) {
                if (preg_match('#^\w+#', $v, $match) !== 1) {
                    ShardingNotSupportedException::raise('Aggregate function "{agg}" is not supported in sharded queries', ['agg' => $v]);
                }

                $agg = strtoupper($match[0]);
                $aggs[$k] = $agg;
                if (in_array($agg, ['COUNT', 'MAX', 'MIN', 'SUM'], true)) {
                    //no-op
                } elseif ($agg === 'AVG') {
                    $sum = $k . '_sum';
                    $this->aggregate[$sum] ??= 'SUM(' . substr($v, 4);

                    $count = $k . '_count';
                    $this->aggregate[$count] ??= 'COUNT(*)';
                } else {
                    ShardingNotSupportedException::raise('Aggregate function "{agg}" is not supported in sharded queries', ['agg' => $v]);
                }
            }

            $this->clauses->fields = $this->buildAggregate($this->aggregate, $this->clauses->group ?? []);

            $rows = [];
            foreach ($shards as $db => $tables) {
                foreach ($tables as $table) {
                    if ($r = $this->query(Table::of($table, $db))) {
                        $rows = $rows ? array_merge($rows, $r) : $r;
                    }
                }
            }

            $result = $this->shardMerger->aggregate($rows, $aggs, $this->clauses->group ?? []);
        }

        if ($this->clauses->order) {
            $result = $this->shardMerger->sort($result, $this->clauses->order);
        }

        return $this->index ? $this->shardMerger->indexBy($result, $this->index) : $result;
    }

    /**
     * {@inheritDoc}
     */
    public function count(string $field = '*'): int
    {
        $copy = clone $this;

        $copy->clauses->fields = "COUNT($field) as [row_count]";
        $copy->clauses->limit = null;
        $copy->clauses->offset = 0;
        $copy->clauses->order = null;
        $copy->index = null;

        $row_count = 0;
        $shards = $this->getShards();
        foreach ($shards as $connection => $tables) {
            foreach ($tables as $table) {
                $result = $copy->query(Table::of($table, $connection));
                $row_count += $this->clauses->group ? count($result) : ($result[0]['row_count'] ?? 0);
            }
        }

        return $row_count;
    }

    /**
     * {@inheritDoc}
     */
    public function exists(): bool
    {
        $copy = clone $this;
        $copy->clauses->fields = '1 as [stub]';
        $copy->clauses->limit = 1;
        $copy->clauses->offset = 0;

        $rs = $copy->execute();

        return isset($rs[0]);
    }

    /**
     * Converts query to SQL string.
     *
     * @return string SQL query string (empty on error)
     */
    public function __toString()
    {
        try {
            return $this->getSql();
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @return list<mixed>
     * {@inheritDoc}
     */
    public function values(string $field): array
    {
        $copy = clone $this;
        $copy->distinct()->select([$field]);

        $values = [];

        $shards = $copy->getShards();
        if (count($shards) === 1 && count(current($shards)) === 1) {
            $connection = key($shards);
            $table = current($shards)[0];
            foreach ($copy->query(Table::of($table, $connection)) as $row) {
                $values[] = $row[$field];
            }
        } else {
            foreach ($shards as $connection => $tables) {
                foreach ($tables as $table) {
                    foreach ($copy->query(Table::of($table, $connection)) as $row) {
                        $value = $row[$field];
                        if (!in_array($value, $values, true)) {
                            $values[] = $value;
                        }
                    }
                }
            }

            if ($copy->clauses->order) {
                if (current($copy->clauses->order) === SORT_ASC) {
                    sort($values);
                } else {
                    rsort($values);
                }
            }
        }

        return $values;
    }

    /**
     * {@inheritDoc}
     */
    public function update(array $fieldValues): int
    {
        $shards = $this->getShards();
        $compiled = $this->predicatePipeline->compile($this->clauses->predicates);
        $conditions = $compiled['conditions'];
        $bind = array_merge($compiled['bind'], $this->clauses->extraBind);

        $affected_count = 0;
        foreach ($shards as $connection => $tables) {
            $db = $this->namedLookup->by(ClientInterface::class, $connection);

            foreach ($tables as $table) {
                $affected_count += $db->update($table, $fieldValues, $conditions, $bind);
            }
        }

        return $affected_count;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(): int
    {
        $shards = $this->getShards();
        $compiled = $this->predicatePipeline->compile($this->clauses->predicates);
        $conditions = $compiled['conditions'];
        $bind = array_merge($compiled['bind'], $this->clauses->extraBind);

        $affected_count = 0;
        foreach ($shards as $connection => $tables) {
            $db = $this->namedLookup->by(ClientInterface::class, $connection);

            foreach ($tables as $table) {
                $affected_count += $db->delete($table, $conditions, $bind);
            }
        }

        return $affected_count;
    }
}
