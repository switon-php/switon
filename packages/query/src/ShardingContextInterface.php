<?php

declare(strict_types=1);

namespace Switon\Query;

/**
 * Contract for extracting shard-routing context from condition predicates.
 *
 * Use when sharding decisions depend on parsed filter fields and values.
 *
 * Road-signs:
 * - extract() reads predicate IR, not raw SQL
 * - eq/in predicates are the main routing inputs
 *
 * @see \Switon\Query\ShardingContext
 */
interface ShardingContextInterface
{
    /**
     * Extracts sharding context from condition predicates.
     *
     * @param array<int, array<string, mixed>> $predicates Condition predicates array
     *
     * @return array<string, mixed> Sharding context
     */
    public function extract(array $predicates): array;
}
