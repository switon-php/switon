<?php

declare(strict_types=1);

namespace Switon\Query;

/**
 * Contract for building and executing query reads.
 *
 * Use when you need:
 * - fluent filters, joins, ordering, and grouping
 * - helper fetch modes (first/get/all/value/values)
 * - pagination and aggregate helpers on the same query object
 *
 * Query implementations may route across shards and merge results.
 *
 * Guidance: Call from() or setTable() before execute(), aggregate(), paginate(), or getSql().
 *
 * Road-signs:
 * - from()/setTable(Table)
 * - where()/ConditionBuilder
 * - shard-aware execution
 * - paginate()
 * - fail Query\Exception
 *
 * @template Model
 *
 * @see \Switon\Query\Query
 * @see \Switon\Query\AbstractQuery
 * @see \Switon\Query\Table
 * @see \Switon\Query\PredicatePipelineInterface
 * @see \Switon\Query\PaginatorInterface
 * @see \Switon\Query\Exception
 * @see \Switon\Orm\EntityManagerInterface Typical consumer
 * @see \Switon\Orm\RepositoryInterface Typical consumer
 */
interface QueryInterface
{
    /**
     * Sets the entity class for ORM operations.
     *
     * @param string $entityClass Entity class name
     *
     * @return static
     */
    public function setEntityClass(string $entityClass): static;

    /**
     * Gets the entity class for ORM operations.
     *
     * @return string|null Entity class name or null if not set
     */
    public function getEntityClass(): ?string;

    /**
     * Sets the table for the query.
     *
     * @param Table $table Table instance containing table name and connection
     *
     * @return static
     */
    public function setTable(Table $table): static;

    /**
     * Sets the column map for property-to-column name mapping.
     *
     * @param array<string, string> $columnMap Property name => column name mapping
     *
     * @return static
     */
    public function setColumnMap(array $columnMap): static;

    /**
     * Sets the table name and optional alias.
     *
     * @param string $table Table name or entity class
     * @param string|null $alias Optional table alias
     *
     * @return static
     */
    public function from(string $table, ?string $alias = null): static;

    /**
     * Selects fields to retrieve from the database.
     *
     * Relation configs (array values) are automatically converted to `with()` calls.
     *
     * @param array<int|string, string|array<string, mixed>> $fields Fields to select. Format: `['field1', 'field2']`, `['alias' => 'field']`, or `['relation_name' => ['field1', 'field2']]`
     *                      Empty array: selects all fields
     *
     * @return static
     */
    public function select(array $fields): static;

    /**
     * Sets whether to select distinct records.
     *
     * @param bool $distinct Whether to select distinct records
     *
     * @return static
     */
    public function distinct(bool $distinct = true): static;

    /**
     * Appends filter predicates using AND semantics.
     *
     * Accepts array filter syntax handled by {@see PredicatePipelineInterface}
     * (comparison, range, IN/NOT IN, pattern matching, and raw expression forms).
     *
     * @param array<int|string, mixed> $filters =entity_var(new Model) Compact filter array parsed by the predicate pipeline
     *
     * @return static
     *
     * @throws \Switon\Query\Exception\InvalidFilterException When filter syntax or operator is invalid
     */
    public function where(array $filters): static;

    /**
     * Adds an equality condition (field = value).
     *
     * @param string $field =entity_field(new Model)
     * @param mixed $value
     *
     * @return static
     */
    public function whereEq(string $field, mixed $value): static;

    /**
     * Adds an IN condition (field IN (values)).
     *
     * @param string $field =entity_field(new Model)
     * @param array<int, mixed> $values
     *
     * @return static
     */
    public function whereIn(string $field, array $values): static;

    /**
     * Sets ORDER BY clauses for result sorting.
     *
     * Scalar list items default to ascending order.
     *
     * @param array<int|string, int|string> $orderBy =entity_var(new Model) ?: entity_field(new Model) ?: [$k=>SORT_ASC, $k=>SORT_DESC] Order specifications
     *
     * @return static
     *
     * @throws \Switon\Core\Exception\InvalidValueException When order fields are invalid
     * @throws \Switon\Query\Exception\InvalidSortDirectionException When sort direction is unsupported
     */
    public function orderBy(array $orderBy): static;

    /**
     * Sets index field for result array indexing.
     *
     * @param callable|string|array{0: string, 1: string} $indexBy =entity_field(new Model) Index field or callback
     *
     * @return static
     */
    public function indexBy(callable|string|array $indexBy): static;

    /**
     * Sets GROUP BY clauses for result grouping.
     *
     * @param list<string>|string $groupBy =entity_var(new Entity) ?: entity_field(new Entity) Group by fields
     *
     * @return static
     *
     * @throws \Switon\Core\Exception\InvalidValueException When group field items are invalid
     */
    public function groupBy(string|array $groupBy): static;

    /**
     * Sets relations to eager load.
     *
     * @param array<int|string, mixed> $with Relations to load
     *
     * @return static
     */
    public function with(array $with): static;

    /**
     * Gets the with configuration array.
     *
     * @return array<int|string, mixed> The with configuration array
     */
    public function getWith(): array;

    /**
     * Sets limit and offset for result pagination.
     *
     * @param int $limit Maximum number of records to return
     * @param int $offset Number of records to skip
     *
     * @return static
     */
    public function limit(int $limit, int $offset = 0): static;

    /**
     * Executes the query and returns results.
     *
     * Implementations may short-circuit and dispatch
     * <code>\Switon\Db\Event\DbQuerySkipped</code> when conditions are unsatisfiable.
     *
     * @return array<int|string, array<string, mixed>> Query results
     *
     * @throws \Switon\Core\Exception\MisuseException When source table is not set before execution
     * @throws \Switon\Sharding\Exception\ShardingNotSupportedException For unsupported sharding scenarios
     *
     * @see \Switon\Query\Query::execute() Default SQL implementation
     * @see \Switon\Query\AbstractQuery::getShards() Shard routing boundary
     */
    public function execute(): array;

    /**
     * Executes aggregate query with expressions.
     *
     * @param array<int|string, string> $expr Aggregate expressions
     *
     * @return array<int|string, array<string, mixed>> Aggregate results
     *
     * @throws \Switon\Core\Exception\MisuseException When source table is not set before execution
     * @throws \Switon\Sharding\Exception\ShardingNotSupportedException For unsupported aggregate in sharded queries
     */
    public function aggregate(array $expr): array;

    /**
     * Creates paginated results.
     *
     * @param int $page Page number (1-based)
     * @param int $size Items per page
     *
     * @return PaginatorInterface Pagination payload with items and page metadata
     *
     * @throws \Switon\Core\Exception\MisuseException When source table is not set before execution
     * @throws \Switon\Sharding\Exception\ShardingNotSupportedException For unsupported sharding scenarios
     *
     * @see \Switon\Query\AbstractQuery::paginate() Default pagination flow
     */
    public function paginate(int $page, int $size = 10): PaginatorInterface;

    /**
     * Sets result mapping function.
     *
     * @param callable $map Mapping function
     *
     * @return static
     */
    public function map(callable $map): static;

    /**
     * Sets fetch type (single or multiple records).
     *
     * @param bool $multiple Whether to return multiple records
     *
     * @return static
     */
    public function setFetchType(bool $multiple): static;

    /**
     * Fetches query results according to the current fetch mode.
     *
     * @return array<int|string, array<string, mixed>>|array<string, mixed>|null Row set, single row, or null when single-row mode finds nothing
     *
     * @see \Switon\Query\AbstractQuery::fetch() Default fetch flow
     */
    public function fetch(): mixed;

    /**
     * Gets the first record from results.
     *
     * @return array<string, mixed>|null First record or null if not found
     */
    public function first(): ?array;

    /**
     * Gets a single record (throws exception if not found).
     *
     * @return array<string, mixed> Single record
     *
     * @throws \Switon\Query\Exception\NotFoundException If no record found
     */
    public function get(): array;

    /**
     * Gets all records from results.
     *
     * @return array<int, array<string, mixed>> All records
     */
    public function all(): array;

    /**
     * Gets a single field value from the first record.
     *
     * @param string $field =entity_field(new Model) Field name
     * @param mixed $default Default value if not found
     *
     * @return mixed Field value or default
     */
    public function value(string $field, mixed $default = null): mixed;

    /**
     * Gets all values for a specific field.
     *
     * @param string $field =entity_field(new Model) Field name
     *
     * @return list<mixed> Array of field values
     *
     * @see \Switon\Query\Query::values() Default SQL implementation
     */
    public function values(string $field): array;

    /**
     * Checks if any records exist.
     *
     * @return bool True if records exist, false otherwise
     *
     * @see \Switon\Query\Query::exists() Default SQL implementation
     */
    public function exists(): bool;

    /**
     * Counts the number of records.
     *
     * @param string $field =entity_field(new Model) Field to count (default: '*')
     *
     * @return int Number of records
     *
     * @see \Switon\Query\Query::count() Default SQL implementation
     */
    public function count(string $field = '*'): int;

    /**
     * Calculates the sum of a field.
     *
     * @param string $field =entity_field(new Model) Field to sum
     *
     * @return int|float|null Sum value or null if no records
     */
    public function sum(string $field): mixed;

    /**
     * Gets the maximum value of a field.
     *
     * @param string $field =entity_field(new Model) Field to get maximum from
     *
     * @return int|float|null Maximum value or null if no records
     */
    public function max(string $field): mixed;

    /**
     * Gets the minimum value of a field.
     *
     * @param string $field =entity_field(new Model) Field to get minimum from
     *
     * @return int|float|null Minimum value or null if no records
     */
    public function min(string $field): mixed;

    /**
     * Calculates the average value of a field.
     *
     * @param string $field =entity_field(new Model) Field to average
     *
     * @return float|null Average value or null if no records
     */
    public function avg(string $field): ?float;

    /**
     * Deletes records matching the query conditions.
     *
     * @return int Number of affected records
     *
     * @see \Switon\Query\Query::delete() Default SQL implementation
     */
    public function delete(): int;

    /**
     * Updates records matching the query conditions.
     *
     * @param array<string, mixed> $fieldValues =entity_var(new Model) Field values to update
     *
     * @return int Number of affected records
     *
     * @see \Switon\Query\Query::update() Default SQL implementation
     */
    public function update(array $fieldValues): int;

    /**
     * Applies a callback function to the query and returns the same instance.
     *
     * @param callable $call Callback function that receives the query instance
     *
     * @return static
     */
    public function tap(callable $call): static;

    /**
     * Adds a JOIN clause to the query.
     *
     * @param Table $table Table to join
     * @param string|null $condition Join condition (e.g., 'users.id = profiles.user_id')
     * @param string|null $alias Table alias
     * @param string|null $type Join type (INNER, LEFT, RIGHT, etc.)
     *
     * @return static
     */
    public function join(Table $table, ?string $condition = null, ?string $alias = null, ?string $type = null): static;

    /**
     * Adds an INNER JOIN clause to the query.
     *
     * @param Table $table Table to join
     * @param string|null $condition Join condition
     * @param string|null $alias Table alias
     *
     * @return static
     */
    public function innerJoin(Table $table, ?string $condition = null, ?string $alias = null): static;

    /**
     * Adds a LEFT JOIN clause to the query.
     *
     * @param Table $table Table to join
     * @param string|null $condition Join condition
     * @param string|null $alias Table alias
     *
     * @return static
     */
    public function leftJoin(Table $table, ?string $condition = null, ?string $alias = null): static;

    /**
     * Adds a RIGHT JOIN clause to the query.
     *
     * @param Table $table Table to join
     * @param string|null $condition Join condition
     * @param string|null $alias Table alias
     *
     * @return static
     */
    public function rightJoin(Table $table, ?string $condition = null, ?string $alias = null): static;

    /**
     * Adds a HAVING clause to the query.
     *
     * @param string|array<string> $having HAVING condition
     * @param array<string, mixed> $bind Bind parameters
     *
     * @return static
     */
    public function having(string|array $having, array $bind = []): static;

    /**
     * Generates and returns the SQL string for the query.
     *
     * @return string The generated SQL string
     *
     * @throws \Switon\Core\Exception\MisuseException When source table is not set before SQL generation
     * @throws \Switon\Sharding\Exception\ShardingTooManyException When query spans multiple tables for this operation
     */
    public function getSql(): string;

    /**
     * Sets the query to use SELECT ... FOR UPDATE for row-level locking.
     *
     * This is useful for pessimistic locking in transactions to prevent other
     * transactions from modifying the selected rows until the current transaction commits.
     *
     * @param bool $forUpdate Whether to use FOR UPDATE clause
     *
     * @return static
     */
    public function forUpdate(bool $forUpdate = true): static;

    /**
     * Gets compiled bind parameters for the query.
     *
     * Returns the full bind map, or a single-entry array when `$key` is provided.
     *
     * @param string|null $key Optional bind key
     *
     * @return array<string, mixed> All bind parameters or the specific parameter value
     */
    public function getBind(?string $key = null): array;

    /**
     * Sets bind parameters for the query.
     *
     * @param array<string, mixed> $bind Bind parameters (key => value pairs)
     * @param bool $merge Whether to merge with existing bind parameters (true) or replace them (false)
     *
     * @return static
     */
    public function setBind(array $bind, bool $merge = true): static;
}
