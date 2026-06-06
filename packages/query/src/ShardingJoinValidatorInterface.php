<?php

declare(strict_types=1);

namespace Switon\Query;

/**
 * Contract for validating whether join queries are sharding-safe.
 *
 * Use when a query includes joins and may target sharded tables.
 *
 * @see \Switon\Query\ShardingJoinValidator
 */
interface ShardingJoinValidatorInterface
{
    /**
     * Validates join table sharding configuration.
     *
     * Implementations should dispatch
     * <code>\Switon\Sharding\Event\ShardValidationFailed</code> before rethrowing
     * validation exceptions so failure logs stay consistent.
     *
     * @param array<string, array<int, string>> $mainShards Main table shards array (may be empty if no matching data)
     * @param array<string, mixed> $context Sharding context
     * @param list<array{0: Table|string, 1: string|null, 2: string|null, 3: string|null}> $joins Join definitions array, each element is [table, condition, alias, type]
     * @param callable|null $joinTableResolver Callable to resolve join table shards: function(string $joinTable, array $context): array
     * @param string|null $connection Database connection name for join tables (if not entity-based)
     */
    public function validate(array $mainShards, array $context, array $joins, ?callable $joinTableResolver = null, ?string $connection = null): void;
}
