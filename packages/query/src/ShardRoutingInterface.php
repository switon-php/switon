<?php

declare(strict_types=1);

namespace Switon\Query;

/**
 * Contract for planning shard routing for a query (main table + joins).
 *
 * Use when query execution needs physical table targets before SQL compilation.
 *
 * @see \Switon\Query\ShardRouting
 * @see \Switon\Query\AbstractQuery::getShards()
 */
interface ShardRoutingInterface
{
    /**
     * Plans shard routing for the main table and validates joins (if any).
     *
     * @param array<int, array<string, mixed>> $predicates Parsed predicate IR from Query.
     * @param list<array{0: Table|string, 1: string|null, 2: string|null, 3: string|null}> $joins Join definitions: [Table, condition, alias, type]
     *
     * @return array<string, array<int, string>> Shards in format: [connection => [table1, table2, ...]]
     */
    public function plan(Table $table, array $predicates, array $joins): array;
}
