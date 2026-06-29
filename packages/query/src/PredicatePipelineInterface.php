<?php

declare(strict_types=1);

namespace Switon\Query;

/**
 * Contract for the predicates pipeline (filters → predicates → conditions/bind).
 *
 * Use when callers and executors must share one filter syntax but operate at different stages.
 *
 * Road-signs:
 * - parse() for filter-array input
 * - compile() for SQL-ready conditions and bind
 *
 * @see \Switon\Query\QueryInterface::where()
 * @see \Switon\Query\ConditionBuilderInterface
 */
interface PredicatePipelineInterface
{
    /**
     * Parses array filters into predicate IR.
     *
     * Guidance: associative array values require an operator suffix on the field key; unsupported shapes are skipped.
     *
     * @param array<string|int, mixed> $filters Query filter array (same syntax as {@see QueryInterface::where()}).
     *
     * @return list<array<string, mixed>> Predicates list.
     */
    public function parse(array $filters): array;

    /**
     * Compiles predicate IR into SQL conditions and bind parameters.
     *
     * @param array<int, array<string, mixed>> $predicates
     *
     * @return array{conditions: list<string>, bind: array<string, mixed>}
     */
    public function compile(array $predicates): array;
}
