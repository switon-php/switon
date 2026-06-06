<?php

declare(strict_types=1);

namespace Switon\Query;

/**
 * Contract for merging query results from multiple shards.
 *
 * Use when combining shard rows in memory for ordering, indexing, or aggregation.
 *
 * Road-signs:
 * - sort() after shard fan-out reads
 * - indexBy() for caller-facing result shape
 * - aggregate() for grouped cross-shard totals
 *
 * @see \Switon\Query\ShardMerger
 */
interface ShardMergerInterface
{
    /**
     * Sort array by multiple fields with direction support.
     *
     * Simulates SQL ORDER BY clause for in-memory result merging.
     *
     * @param array<int, array<string, mixed>> $ar Array to sort (modified in place)
     * @param array<int|string, int|string> $sort Sort specification: ['field' => SORT_ASC|SORT_DESC, ...] or ['field1', 'field2']
     *
     * @return array<int, array<string, mixed>> Sorted array
     */
    public function sort(array &$ar, array $sort): array;

    /**
     * Indexes rows by a field, mapping pair, or callback.
     *
     * Simulates SQL INDEX BY clause for result indexing.
     *
     * @param array<int, array<string, mixed>> $ar Array to index
     * @param mixed $index Field name, [key => value] mapping, or callback
     *
     * @return array<int|string, mixed> Indexed array
     */
    public function indexBy(array $ar, mixed $index): array;

    /**
     * Aggregate rows with grouping support.
     *
     * Simulates SQL GROUP BY with aggregate functions (MAX, MIN, COUNT, SUM, AVG)
     * for merging results from multiple shards.
     *
     * @param array<int, array<string, mixed>> $rows Rows to aggregate
     * @param array<int|string, string> $aggs Aggregation specification: ['field' => 'MAX'|'MIN'|'COUNT'|'SUM'|'AVG']
     * @param array<int|string, string> $group Group by fields
     *
     * @return array<int, array<string, mixed>> Aggregated results
     */
    public function aggregate(array $rows, array $aggs, array $group = []): array;
}
