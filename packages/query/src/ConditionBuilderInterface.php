<?php

declare(strict_types=1);

namespace Switon\Query;

/**
 * Contract for building query predicates and bind parameters.
 *
 * Use when converting external filter input (for example HTTP filters) into
 * SQL-ready conditions while keeping a stable fluent API.
 *
 * Road-signs:
 * - where(array) parses compact filter syntax
 * - where* helpers append typed predicates
 * - build() emits conditions + bind map
 * - fail InvalidFilterException/UnknownOperatorException
 *
 * @see \Switon\Query\AbstractConditionBuilder
 * @see \Switon\Query\ConditionBuilder
 * @see \Switon\Query\QueryInterface::where()
 */
interface ConditionBuilderInterface
{
    /**
     * Adds equality predicate.
     */
    public function whereEq(string $field, mixed $value): static;

    /**
     * Adds comparison predicate by operator.
     *
     * @throws \Switon\Db\Exception\UnknownOperatorException When operator is unsupported
     */
    public function whereCmp(string $field, string $operator, mixed $value): static;

    /**
     * Adds MOD predicate.
     */
    public function whereMod(string $field, int $divisor, int $remainder): static;

    /**
     * Adds a raw SQL expression predicate.
     *
     * @param array<string, mixed> $bind
     */
    public function whereExpr(string $expr, ?array $bind = null): static;

    /**
     * Adds BETWEEN predicate.
     */
    public function whereBetween(string $field, mixed $min, mixed $max): static;

    /**
     * Adds NOT BETWEEN predicate.
     */
    public function whereNotBetween(string $field, mixed $min, mixed $max): static;

    /**
     * Adds an IN predicate.
     *
     * @param array<int, mixed> $values
     */
    public function whereIn(string $field, array $values): static;

    /**
     * Adds a raw condition fragment.
     *
     * @param array<string, mixed> $bind
     */
    public function whereRaw(string $filter, ?array $bind = null): static;

    /**
     * Adds a NOT IN predicate.
     *
     * @param array<int, mixed> $values
     */
    public function whereNotIn(string $field, array $values): static;

    /**
     * Adds FIND_IN_SET predicate.
     */
    public function whereInset(string $field, string $value): static;

    /**
     * Adds NOT FIND_IN_SET predicate.
     */
    public function whereNotInset(string $field, string $value): static;

    /**
     * Adds a LIKE predicate.
     *
     * @param string|array<int, string> $fields
     */
    public function whereLike(string|array $fields, string $value): static;

    /**
     * Adds a NOT LIKE predicate.
     *
     * @param string|array<int, string> $fields
     */
    public function whereNotLike(string|array $fields, string $value): static;

    /**
     * Adds a contains predicate.
     *
     * @param string|array<int, string> $fields
     */
    public function whereContains(string|array $fields, string $value): static;

    /**
     * Adds a NOT contains predicate.
     *
     * @param string|array<int, string> $fields
     */
    public function whereNotContains(string|array $fields, string $value): static;

    /**
     * Adds a starts-with predicate.
     *
     * @param string|array<int, string> $fields
     */
    public function whereStartsWith(string|array $fields, string $value, ?int $length = null): static;

    /**
     * Adds a NOT starts-with predicate.
     *
     * @param string|array<int, string> $fields
     */
    public function whereNotStartsWith(string|array $fields, string $value, ?int $length = null): static;

    /**
     * Adds an ends-with predicate.
     *
     * @param string|array<int, string> $fields
     */
    public function whereEndsWith(string|array $fields, string $value): static;

    /**
     * Adds a NOT ends-with predicate.
     *
     * @param string|array<int, string> $fields
     */
    public function whereNotEndsWith(string|array $fields, string $value): static;

    /**
     * Adds REGEXP predicate.
     */
    public function whereRegex(string $field, string $regex, string $flags = ''): static;

    /**
     * Adds NOT REGEXP predicate.
     */
    public function whereNotRegex(string $field, string $regex, string $flags = ''): static;

    /**
     * Adds IS NULL predicate.
     */
    public function whereNull(string $field): static;

    /**
     * Adds IS NOT NULL predicate.
     */
    public function whereNotNull(string $field): static;

    /**
     * Parses compact filter syntax and appends corresponding predicates.
     *
     * @param array<string|int, mixed> $filters
     *
     * @throws \Switon\Query\Exception\InvalidFilterException When filter key/value/operator is invalid
     */
    public function where(array $filters): static;

    /**
     * Builds SQL conditions and bind parameters from collected predicates.
     *
     * @return array{conditions: array<string>, bind: array<string, mixed>}
     */
    public function build(): array;

    /**
     * Returns collected predicates.
     *
     * @return list<array<string, mixed>>
     */
    public function getPredicates(): array;

    /**
     * Returns built condition fragments.
     *
     * @return array<string>
     */
    public function getConditions(): array;

    /**
     * Returns the full bind map or one bind value by key.
     */
    public function getBind(?string $key = null): mixed;

    /**
     * Sets bind parameters.
     *
     * @param array<string, mixed> $bind
     */
    public function setBind(array $bind, bool $merge = true): void;
}
