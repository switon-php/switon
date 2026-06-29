<?php

declare(strict_types=1);

namespace Switon\Query;

use ArrayIterator;
use IteratorAggregate;
use JsonSerializable;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\MisuseException;
use Switon\Core\MakerInterface;
use Switon\Query\Exception\InvalidSortDirectionException;
use Switon\Query\Exception\NotFoundException;
use Switon\Sharding\Exception\ShardingTooManyException;
use Switon\Sharding\ShardingManagerInterface;

use function array_keys;
use function array_merge;
use function count;
use function current;
use function is_array;
use function is_int;
use function is_string;
use function key;
use function max;
use function preg_split;
use function str_contains;
use function strpos;
use function substr;

/**
 * Base implementation for shared query state and execution flow.
 *
 * Use when implementing <code>QueryInterface</code> with common behaviors such as
 * sharding resolution, relation loading, and fetch helpers.
 *
 * @see \Switon\Query\QueryInterface
 * @see \Switon\Query\Query
 * @see \Switon\Query\Table
 * @see \Switon\Query\PaginatorInterface
 * @see \Switon\Query\ShardingContextInterface
 *
 * @implements QueryInterface<mixed>
 * @implements IteratorAggregate<int, array<string, mixed>>
 *
 * @see \Switon\Query\Exception
 */
abstract class AbstractQuery implements QueryInterface, IteratorAggregate, JsonSerializable
{
    #[Autowired] protected MakerInterface $maker;
    #[Autowired] protected ShardingManagerInterface $shardingManager;
    #[Autowired] protected ShardingContextInterface $shardingContext;
    #[Autowired] protected ShardingJoinValidatorInterface $shardingJoinValidator;
    #[Autowired] protected ShardRoutingInterface $shardRouting;
    #[Autowired] protected PredicatePipelineInterface $predicatePipeline;

    protected Clauses $clauses;

    /** @var string|null Entity class name for ORM operations. */
    protected ?string $entityClass = null;

    /** @var bool|null Whether to return multiple records (null = auto-detect). */
    protected ?bool $multiple = null;

    /** @var array<int|string, mixed> Relations to eager load. */
    protected array $with = [];

    /** @var mixed Index field for result array. */
    protected mixed $index = null;

    /** @var array<int|string, string>|null Aggregate expressions. */
    protected ?array $aggregate = null;

    /** @var callable|mixed Result mapping function. */
    protected mixed $map = null;

    public function __construct()
    {
        $this->clauses = new Clauses();
    }

    public function getClauses(): Clauses
    {
        return $this->clauses;
    }

    /**
     * @return ArrayIterator<int|string, mixed>
     * {@inheritDoc}
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->all());
    }

    /**
     * Normalizes sharding context for main-table routing when joins exist.
     *
     * Removes table prefixes (e.g., "user.id" → "id") so shard keys match main table fields.
     *
     * @param array<string, mixed> $context
     * @param list<array{0: Table|string, 1: string|null, 2: string|null, 3: string|null}> $joins
     *
     * @return array<string, mixed>
     */
    protected function normalizeShardingContext(array $context, array $joins): array
    {
        if ($joins === []) {
            return $context;
        }

        $mainContext = $context;
        foreach ($context as $k => $v) {
            if (($pos = strpos($k, '.')) !== false) {
                $mainContext[substr($k, $pos + 1)] = $v;
            }
        }

        return $mainContext;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->all();
    }

    /**
     * {@inheritDoc}
     */
    public function setEntityClass(string $entityClass): static
    {
        $this->entityClass = $entityClass;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }

    /**
     * @param array<string, string> $columnMap
     * {@inheritDoc}
     */
    public function setColumnMap(array $columnMap): static
    {
        $this->clauses->columnMap = $columnMap;

        return $this;
    }

    /**
     * Gets joins for sharding resolution.
     *
     * SQL databases return their joins array, others return empty array.
     *
     * @return list<array{0: Table|string, 1: string|null, 2: string|null, 3: string|null}> Join definitions
     */
    protected function getJoins(): array
    {
        return [];
    }

    /**
     * Resolves shards for the query using ShardingManager.
     *
     * @return array<string, array<int, string>> Shards in format: [connection => [table1, table2, ...]]
     */
    protected function getShards(): array
    {
        if ($this->clauses->table === null) {
            MisuseException::raise('Query table not set: call from("table") / setTable(), or use Repository methods (which handle this automatically)');
        }

        return $this->shardRouting->plan(
            $this->clauses->table,
            $this->clauses->predicates,
            $this->getJoins(),
        );
    }

    /**
     * Gets unique shard (single database and table).
     *
     * @return Table Table for the unique shard
     *
     * @throws \Switon\Sharding\Exception\ShardingTooManyException If multiple shards resolved
     */
    protected function getUniqueShard(): Table
    {
        $shards = $this->getShards();

        if (count($shards) !== 1) {
            ShardingTooManyException::raise('Query spans {count} databases ({databases}), single database required for this operation', ['count' => count($shards), 'databases' => implode(', ', array_keys($shards))]);
        }

        $tables = current($shards);
        if (count($tables) !== 1) {
            ShardingTooManyException::raise('Query spans {count} tables ({tables}), single table required for this operation', ['count' => count($tables), 'tables' => implode(', ', $tables)]);
        }

        return Table::of($tables[0], key($shards));
    }

    /**
     * {@inheritDoc}
     */
    public function from(string $table, ?string $alias = null): static
    {
        if ($table) {
            if (str_contains($table, '\\')) {
                // Entity class path: record class hint for ORM-style callers.
                $this->setEntityClass($table);
            } else {
                // Plain table name path: make from() executable without extra setTable().
                $this->setTable(Table::of($table));
            }
            $this->clauses->alias = $alias;
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setTable(Table $table): static
    {
        $this->clauses->table = $table;

        return $this;
    }

    /**
     * Gets the table.
     *
     * @return Table|null Table, or null if not set
     */
    public function getTable(): ?Table
    {
        return $this->clauses->table;
    }

    /**
     * {@inheritDoc}
     */
    public function distinct(bool $distinct = true): static
    {
        $this->clauses->distinct = $distinct;

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int|string, mixed> $filters
     */
    public function where(array $filters): static
    {
        $predicates = $this->predicatePipeline->parse($filters);
        if ($predicates !== []) {
            $this->clauses->predicates = array_merge($this->clauses->predicates, $predicates);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function whereEq(string $field, mixed $value): static
    {
        $this->clauses->predicates[] = [
            'type' => 'eq',
            'field' => $field,
            'value' => $value,
        ];
        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int, mixed> $values
     */
    public function whereIn(string $field, array $values): static
    {
        $this->clauses->predicates[] = [
            'type' => 'in',
            'field' => $field,
            'values' => $values,
        ];
        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @param list<string>|string $groupBy
     */
    public function groupBy(string|array $groupBy): static
    {
        if (is_string($groupBy)) {
            $this->clauses->group = preg_split('#[\s,]+#', $groupBy, -1, PREG_SPLIT_NO_EMPTY);
        } else {
            $this->clauses->group = array_map(
                static fn (mixed $item): string => (string)$item,
                $groupBy
            );
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int|string, int|string> $orderBy
     */
    public function orderBy(array $orderBy): static
    {
        if ($this->clauses->order === null) {
            $this->clauses->order = [];
        }
        foreach ($orderBy as $k => $v) {
            if (is_int($k)) {
                $this->clauses->order[(string)$v] = SORT_ASC;
            } else {
                $field = (string)$k;
                if ($v === SORT_ASC || $v === SORT_DESC) {
                    $this->clauses->order[$field] = $v;
                } elseif ($v === 'ASC' || $v === 'asc') {
                    $this->clauses->order[$field] = SORT_ASC;
                } elseif ($v === 'DESC' || $v === 'desc') {
                    $this->clauses->order[$field] = SORT_DESC;
                } else {
                    InvalidSortDirectionException::raise('orderBy() invalid direction for "{field}": expected SORT_ASC, SORT_DESC, "asc" or "desc", got {dir}', ['field' => $field, 'dir' => is_string($v) ? $v : gettype($v)]);
                }
            }
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @param callable|string|array{0: string, 1: string} $indexBy
     */
    public function indexBy(string|array|callable $indexBy): static
    {
        if (is_array($indexBy)) {
            $this->select([key($indexBy), current($indexBy)]);
        }

        $this->index = $indexBy;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function limit(int $limit, int $offset = 0): static
    {
        $this->clauses->limit = $limit;
        $this->clauses->offset = $offset;

        return $this;
    }

    /**
     * @param array<int|string, string|array<string|int, mixed>|callable|QueryInterface<mixed>> $with
     * {@inheritDoc}
     */
    public function with(array $with): static
    {
        $with = $this->with ? array_merge($this->with, $with) : $with;

        foreach ($with as $k => $v) {
            $name = is_string($k) ? $k : $v;
            if (($pos = strpos($name, '.')) === false) {
                continue;
            }
            $parent_name = substr($name, 0, $pos);
            $child_name = substr($name, $pos + 1);
            if (!isset($with[$parent_name])) {
                continue;
            }

            $parent_value = $with[$parent_name];
            if (!$parent_value instanceof QueryInterface) {
                // Not a QueryInterface, skip processing nested relation (handled by Repository)
                unset($with[$k]);
                continue;
            }

            $with[$parent_name]->with(is_int($k) ? [$child_name] : [$child_name => $v]);
            unset($with[$k]);
        }

        $this->with = $with;

        return $this;
    }

    /**
     * @return array<int|string, string|array<string|int, mixed>|callable|QueryInterface<mixed>>
     * {@inheritDoc}
     */
    public function getWith(): array
    {
        return $this->with;
    }

    /**
     * {@inheritDoc}
     */
    public function map(callable $map): static
    {
        $this->map = $map;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setFetchType(bool $multiple): static
    {
        $this->multiple = $multiple;

        return $this;
    }

    /**
     * @return array<int|string, mixed>
     * {@inheritDoc}
     */
    public function fetch(): mixed
    {
        $rows = $this->execute();

        if (($map = $this->map) !== null) {
            foreach ($rows as $k => $v) {
                $rows[$k] = $map($v);
            }
        }

        if ($this->multiple === false) {
            return $rows[0] ?? null;
        } else {
            return $rows;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function paginate(int $page, int $size = 10): PaginatorInterface
    {
        $page = max(1, $page);
        $size = max(1, $size);

        $this->limit($size, ($page - 1) * $size);

        $items = $this->fetch();

        if (count($items) === 0) {
            $count = $this->clauses->offset === 0 ? 0 : $this->count();
        } elseif (count($items) === $size) {
            $count = $this->count();
        } else {
            $count = $this->clauses->offset + count($items);
        }

        $paginator = $this->maker->make(PaginatorInterface::class);
        $paginator->setItems($items);
        return $paginator->paginate($count, $page, $size);
    }

    /**
     * @return array<string, mixed>|null
     * {@inheritDoc}
     */
    public function first(): ?array
    {
        $r = $this->limit(1)->fetch();
        return $r ? $r[0] : null;
    }

    /**
     * @return array<string, mixed>
     * {@inheritDoc}
     */
    public function get(): array
    {
        if (!$r = $this->first()) {
            $table = $this->clauses->table?->table ?? 'unknown';
            NotFoundException::raise('Record not found in table "{table}"', ['table' => $table]);
        }

        return $r;
    }

    /**
     * @return array<int|string, mixed>
     * {@inheritDoc}
     */
    public function all(): array
    {
        return $this->fetch();
    }

    /**
     * {@inheritDoc}
     */
    public function value(string $field, mixed $default = null): mixed
    {
        $rs = $this->select([$field])->limit(1)->execute();
        return $rs[0][$field] ?? $default;
    }

    /**
     * {@inheritDoc}
     */
    public function sum(string $field): mixed
    {
        return $this->aggregate(['r' => "SUM($field)"])[0]['r'];
    }

    /**
     * {@inheritDoc}
     */
    public function max(string $field): mixed
    {
        return $this->aggregate(['r' => "MAX($field)"])[0]['r'];
    }

    /**
     * {@inheritDoc}
     */
    public function min(string $field): mixed
    {
        return $this->aggregate(['r' => "MIN($field)"])[0]['r'];
    }

    /**
     * {@inheritDoc}
     */
    public function avg(string $field): ?float
    {
        return (float)$this->aggregate(['r' => "AVG($field)"])[0]['r'];
    }

    /**
     * {@inheritDoc}
     */
    public function tap(callable $call): static
    {
        $call($this);

        return $this;
    }

}
