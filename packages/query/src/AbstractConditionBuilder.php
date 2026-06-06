<?php

declare(strict_types=1);

namespace Switon\Query;

use Switon\Query\Exception\InvalidFilterException;

use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function str_contains;
use function strlen;
use function substr;
use function trim;

/**
 * Base parser for array-based query filters.
 *
 * Use when implementing <code>ConditionBuilderInterface</code> and reusing the
 * shared filter-key syntax parser.
 *
 * Road-signs:
 * - `~=` between
 * - `?` optional suffix
 * - InvalidFilterException
 *
 * @see \Switon\Query\ConditionBuilderInterface
 * @see \Switon\Query\ConditionBuilder
 * @see \Switon\Http\RequestInterface::filters() Typical producer (HTTP request filters)
 * @see \Switon\Orm\FilterPreprocessorInterface
 * @see \Switon\Orm\RepositoryInterface Typical consumer (ORM repositories)
 */
abstract class AbstractConditionBuilder implements ConditionBuilderInterface
{
    /**
     * @param array<string|int, mixed> $filters
     * {@inheritDoc}
     */
    public function where(array $filters): static
    {
        foreach ($filters as $filter => $value) {
            if (is_int($filter)) {
                $this->whereExpr($value);
            } elseif (is_array($value)) {
                if (preg_match('#([~@!<>=%]+)$#', $filter, $match)) {
                    $operator = $match[1];
                    $field = substr($filter, 0, -strlen($operator));
                    if ($operator === '~=') {
                        if (count($value) !== 2) {
                            InvalidFilterException::raise('Range filter "{filter}" requires exactly 2 values [min, max], got {count}', ['filter' => $filter, 'count' => count($value)]);
                        }
                        $this->whereBetween($field, $value[0], $value[1]);
                    } elseif ($operator === '!=') {
                        $this->whereNotIn($field, $value);
                    } elseif ($operator === '=') {
                        $this->whereIn($field, $value);
                    } elseif ($operator === '%=') {
                        $this->whereMod($field, $value[0], $value[1]);
                    } else {
                        InvalidFilterException::raise('Unknown filter operator "{op}" in "{filter}", supported: ~=, !=, =, %=', ['op' => $operator, 'filter' => $filter]);
                    }
                } elseif (!$value || isset($value[0])) {
                    $this->whereIn($filter, $value);
                } else {
                    InvalidFilterException::raise('Array value for filter "{filter}" must be indexed array or have operator suffix', ['filter' => $filter]);
                }
            } elseif (str_contains($filter, ',') && preg_match('#^([\w,.]+)([<>=!^$*~,@?]*)$#', $filter, $matches) === 1) {
                // Comma-separated fields with optional operator (e.g., 'field1,field2*=')
                [, $fields, $operator] = $matches;

                if (str_contains($operator, '?')) {
                    $value = is_string($value) ? trim($value) : $value;
                    if ($value === '' || $value === null) {
                        continue;
                    }
                    $operator = substr($operator, 0, -1);
                }

                if ($operator === '') {
                    // Comma-separated fields without operator are not supported
                    InvalidFilterException::raise('Multi-field filter "{filter}" requires operator suffix (^=, $=, or *=)', ['filter' => $filter]);
                } else {
                    // Has operator: parse fields and apply operator to all fields with OR logic
                    $fieldArray = preg_split('#\s*,\s*#', $fields, -1, PREG_SPLIT_NO_EMPTY);

                    // Only these operators support multiple fields with OR logic
                    if ($operator === '^=') {
                        $this->whereStartsWith($fieldArray, $value);
                    } elseif ($operator === '$=') {
                        $this->whereEndsWith($fieldArray, $value);
                    } elseif ($operator === '*=') {
                        $this->whereContains($fieldArray, $value);
                    } elseif (count($fieldArray) === 1) {
                        // Single field with operator: handle as normal
                        $field = $fieldArray[0];
                        if (in_array($operator, ['=', '~=', '!=', '>', '>=', '<', '<='], true)) {
                            $this->whereCmp($field, $operator, $value);
                        } elseif ($operator === ',=') {
                            $this->whereInset($field, $value);
                        } else {
                            InvalidFilterException::raise('Unknown filter operator "{op}" in "{filter}", supported: =, ~=, !=, >, >=, <, <=, ,=', ['op' => $operator, 'filter' => $filter]);
                        }
                    } else {
                        // Multiple fields with unsupported operator
                        InvalidFilterException::raise('Multi-field filter "{filter}" only supports ^=, $=, *= operators, got "{op}"', ['filter' => $filter, 'op' => $operator]);
                    }
                }
            } elseif (preg_match('#^([\w.]+)([<>=!^$*~,@?]*)$#', $filter, $matches) === 1) {
                [, $field, $operator] = $matches;

                if (str_contains($operator, '?')) {
                    $value = is_string($value) ? trim($value) : $value;
                    if ($value === '' || $value === null) {
                        continue;
                    }
                    $operator = substr($operator, 0, -1);
                }

                if ($operator === '') {
                    $operator = '=';
                }

                if (in_array($operator, ['=', '~=', '!=', '>', '>=', '<', '<='], true)) {
                    $this->whereCmp($field, $operator, $value);
                } elseif ($operator === '^=') {
                    $this->whereStartsWith($field, $value);
                } elseif ($operator === '$=') {
                    $this->whereEndsWith($field, $value);
                } elseif ($operator === '*=') {
                    $this->whereContains($field, $value);
                } elseif ($operator === ',=') {
                    $this->whereInset($field, $value);
                } else {
                    InvalidFilterException::raise('Unknown filter operator "{op}" in "{filter}", supported: =, ~=, !=, >, >=, <, <=, ^=, $=, *=, ,=', ['op' => $operator, 'filter' => $filter]);
                }
            } else {
                InvalidFilterException::raise('Invalid filter key format "{filter}", expected: field, field>=, field^=, etc.', ['filter' => $filter]);
            }
        }

        return $this;
    }

}
