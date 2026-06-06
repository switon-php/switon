<?php

declare(strict_types=1);

namespace Switon\Query;

use Switon\Db\Exception\UnknownOperatorException;

use function array_map;
use function array_merge;
use function current;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_replace;
use function preg_split;
use function str_contains;
use function str_pad;
use function str_replace;
use function strtr;

/**
 * Default SQL implementation of <code>ConditionBuilderInterface</code>.
 *
 * Use when you need array filter syntax compiled into SQL condition fragments
 * plus bind parameters.
 *
 * @see \Switon\Query\ConditionBuilderInterface
 * @see \Switon\Query\AbstractConditionBuilder
 */
class ConditionBuilder extends AbstractConditionBuilder
{
    /** @var list<array<string, mixed>> Collected condition predicates. */
    protected array $predicates = [];

    /** @var array{conditions: list<string>, bind: array<string, mixed>}|null Cached build result. */
    protected ?array $builtResult = null;

    /**
     * {@inheritDoc}
     */
    public function whereEq(string $field, mixed $value): static
    {
        $this->predicates[] = [
            'type' => 'eq',
            'field' => $field,
            'value' => $value,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function whereCmp(string $field, string $operator, mixed $value): static
    {
        if ($operator === '=') {
            return $this->whereEq($field, $value);
        }

        $this->predicates[] = [
            'type' => 'cmp',
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function whereMod(string $field, int $divisor, int $remainder): static
    {
        $this->predicates[] = [
            'type' => 'mod',
            'field' => $field,
            'divisor' => $divisor,
            'remainder' => $remainder,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * @param array<string, mixed> $bind
     * {@inheritDoc}
     */
    public function whereExpr(string $expr, ?array $bind = null): static
    {
        $this->predicates[] = [
            'type' => 'expr',
            'expr' => $expr,
            'bind' => $bind ?? [],
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function whereBetween(string $field, mixed $min, mixed $max): static
    {
        if ($min === null || $min === '') {
            if ($max === null || $max === '') {
                return $this;
            }
            return $this->whereCmp($field, '<=', $max);
        } elseif ($max === null || $max === '') {
            return $this->whereCmp($field, '>=', $min);
        }

        $this->predicates[] = [
            'type' => 'between',
            'field' => $field,
            'min' => $min,
            'max' => $max,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function whereNotBetween(string $field, mixed $min, mixed $max): static
    {
        if ($min === null || $min === '') {
            if ($max === null || $max === '') {
                return $this;
            }
            return $this->whereCmp($field, '>', $max);
        } elseif ($max === null || $max === '') {
            return $this->whereCmp($field, '<', $min);
        }

        $this->predicates[] = [
            'type' => 'not_between',
            'field' => $field,
            'min' => $min,
            'max' => $max,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * @param array<int, mixed> $values
     * {@inheritDoc}
     */
    public function whereIn(string $field, array $values): static
    {
        $this->predicates[] = [
            'type' => 'in',
            'field' => $field,
            'values' => $values,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * @param array<string, mixed> $bind
     * {@inheritDoc}
     */
    public function whereRaw(string $filter, ?array $bind = null): static
    {
        $this->predicates[] = [
            'type' => 'raw',
            'filter' => $filter,
            'bind' => $bind ?? [],
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * @param array<int, mixed> $values
     * {@inheritDoc}
     */
    public function whereNotIn(string $field, array $values): static
    {
        $this->predicates[] = [
            'type' => 'not_in',
            'field' => $field,
            'values' => $values,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function whereInset(string $field, string $value): static
    {
        $this->predicates[] = [
            'type' => 'inset',
            'field' => $field,
            'value' => $value,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function whereNotInset(string $field, string $value): static
    {
        $this->predicates[] = [
            'type' => 'not_inset',
            'field' => $field,
            'value' => $value,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * @param array<int, string> $fields
     * {@inheritDoc}
     */
    public function whereLike(string|array $fields, string $value): static
    {
        if ($value === '') {
            return $this;
        }

        if (is_string($fields) && str_contains($fields, ',')) {
            $fields = preg_split('#[\s,]+#', $fields, -1, PREG_SPLIT_NO_EMPTY);
        }

        $this->predicates[] = [
            'type' => 'like',
            'fields' => is_array($fields) ? $fields : [$fields],
            'value' => $value,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * @param array<int, string> $fields
     * {@inheritDoc}
     */
    public function whereNotLike(string|array $fields, string $value): static
    {
        if ($value === '') {
            return $this;
        }

        if (is_string($fields) && str_contains($fields, ',')) {
            $fields = preg_split('#[\s,]+#', $fields, -1, PREG_SPLIT_NO_EMPTY);
        }

        $this->predicates[] = [
            'type' => 'not_like',
            'fields' => is_array($fields) ? $fields : [$fields],
            'value' => $value,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * @param array<int, string> $fields
     * {@inheritDoc}
     */
    public function whereContains(string|array $fields, string $value): static
    {
        return $value === '' ? $this : $this->whereLike($fields, '%' . $value . '%');
    }

    /**
     * @param array<int, string> $fields
     * {@inheritDoc}
     */
    public function whereNotContains(string|array $fields, string $value): static
    {
        return $value === '' ? $this : $this->whereNotLike($fields, '%' . $value . '%');
    }

    /**
     * @param array<int, string> $fields
     * {@inheritDoc}
     */
    public function whereStartsWith(string|array $fields, string $value, ?int $length = null): static
    {
        if ($value !== '') {
            $this->whereLike($fields, $length === null ? $value . '%' : str_pad($value, $length, '_'));
        }
        return $this;
    }

    /**
     * @param array<int, string> $fields
     * {@inheritDoc}
     */
    public function whereNotStartsWith(string|array $fields, string $value, ?int $length = null): static
    {
        if ($value !== '') {
            $this->whereNotLike($fields, $length === null ? $value . '%' : str_pad($value, $length, '_'));
        }
        return $this;
    }

    /**
     * @param array<int, string> $fields
     * {@inheritDoc}
     */
    public function whereEndsWith(string|array $fields, string $value): static
    {
        return $value === '' ? $this : $this->whereLike($fields, '%' . $value);
    }

    /**
     * @param array<int, string> $fields
     * {@inheritDoc}
     */
    public function whereNotEndsWith(string|array $fields, string $value): static
    {
        return $value === '' ? $this : $this->whereNotLike($fields, '%' . $value);
    }

    /**
     * {@inheritDoc}
     */
    public function whereRegex(string $field, string $regex, string $flags = ''): static
    {
        $this->predicates[] = [
            'type' => 'regex',
            'field' => $field,
            'regex' => $regex,
            'flags' => $flags,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function whereNotRegex(string $field, string $regex, string $flags = ''): static
    {
        $this->predicates[] = [
            'type' => 'not_regex',
            'field' => $field,
            'regex' => $regex,
            'flags' => $flags,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function whereNull(string $field): static
    {
        $this->predicates[] = [
            'type' => 'null',
            'field' => $field,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function whereNotNull(string $field): static
    {
        $this->predicates[] = [
            'type' => 'not_null',
            'field' => $field,
        ];
        $this->builtResult = null;
        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     * {@inheritDoc}
     */
    public function getPredicates(): array
    {
        return $this->predicates;
    }

    /**
     * {@inheritDoc}
     */
    public function build(): array
    {
        if ($this->builtResult !== null) {
            return $this->builtResult;
        }

        $conditions = [];
        $bind = [];

        foreach ($this->predicates as $spec) {
            $result = $this->buildSpec($spec);
            if ($result['condition'] !== null) {
                $conditions[] = $result['condition'];
            }

            /** @noinspection SlowArrayOperationsInLoopInspection */
            $bind = array_merge($bind, $result['bind']);
        }

        $this->builtResult = [
            'conditions' => $conditions,
            'bind' => $bind,
        ];

        return $this->builtResult;
    }

    /**
     * Builds a single condition predicate.
     *
     * @param array<string, mixed> $spec Condition predicate
     *
     * @return array{condition: string|null, bind: array<string, mixed>} <code>condition</code> is <code>null</code>
     * when the predicate type is unsupported or when a <code>not_in</code> predicate has an empty value list.
     */
    protected function buildSpec(array $spec): array
    {
        $type = $spec['type'];
        $field = $spec['field'] ?? null;

        return match ($type) {
            'eq' => $this->buildEq($field, $spec['value']),
            'cmp' => $this->buildCmp($field, $spec['operator'], $spec['value']),
            'mod' => $this->buildMod($field, $spec['divisor'], $spec['remainder']),
            'expr' => $this->buildExpr($spec['expr'], $spec['bind'] ?? []),
            'between' => $this->buildBetween($field, $spec['min'], $spec['max']),
            'not_between' => $this->buildNotBetween($field, $spec['min'], $spec['max']),
            'in' => $this->buildIn($field, $spec['values']),
            'not_in' => $this->buildNotIn($field, $spec['values']),
            'raw' => $this->buildRaw($spec['filter'], $spec['bind'] ?? []),
            'inset' => $this->buildInset($field, $spec['value']),
            'not_inset' => $this->buildNotInset($field, $spec['value']),
            'like' => $this->buildLike($spec['fields'], $spec['value']),
            'not_like' => $this->buildNotLike($spec['fields'], $spec['value']),
            'regex' => $this->buildRegex($field, $spec['regex'], $spec['flags'] ?? ''),
            'not_regex' => $this->buildNotRegex($field, $spec['regex'], $spec['flags'] ?? ''),
            'null' => $this->buildNull($field),
            'not_null' => $this->buildNotNull($field),
            default => ['condition' => null, 'bind' => []],
        };
    }

    /**
     * Builds equality condition.
     *
     * @param string $field Field name
     * @param mixed $value Value to compare
     *
     * @return array{condition: string, bind: array}
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildEq(string $field, mixed $value): array
    {
        $normalizedField = preg_replace('#\w+#', '[\\0]', $field);

        if ($value === null) {
            return [
                'condition' => $normalizedField . ' IS NULL',
                'bind' => [],
            ];
        }

        $bind_key = str_contains($field, '.') ? strtr($field, '.', '_') : $field;
        return [
            'condition' => "$normalizedField=:$bind_key",
            'bind' => [$bind_key => $value],
        ];
    }

    /**
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildCmp(string $field, string $operator, mixed $value): array
    {
        $bind_key = str_contains($field, '.') ? strtr($field, '.', '_') : $field;
        $normalizedField = preg_replace('#\w+#', '[\\0]', $field);

        if ($operator === '~=') {
            if ($value === 0 || $value === 0.0) {
                return [
                    'condition' => "$normalizedField IS NULL OR $normalizedField=0",
                    'bind' => [],
                ];
            } elseif ($value === '') {
                return [
                    'condition' => "$normalizedField IS NULL OR $normalizedField=''",
                    'bind' => [],
                ];
            } else {
                return [
                    'condition' => $normalizedField . '=:' . $bind_key,
                    'bind' => [$bind_key => $value],
                ];
            }
        } elseif ($operator === '!=') {
            if ($value === null) {
                return [
                    'condition' => $normalizedField . ' IS NOT NULL',
                    'bind' => [],
                ];
            } else {
                return [
                    'condition' => $normalizedField . '!=' . ':' . $bind_key,
                    'bind' => [$bind_key => $value],
                ];
            }
        } elseif (in_array($operator, ['>', '>=', '<', '<='], true)) {
            return [
                'condition' => $normalizedField . $operator . ':' . $bind_key,
                'bind' => [$bind_key => $value],
            ];
        } else {
            UnknownOperatorException::raise('Unknown query operator "{operator}" in condition', ['operator' => $operator]);
        }
    }

    /**
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildMod(string $field, int $divisor, int $remainder): array
    {
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';
        return [
            'condition' => "$normalizedField%$divisor=$remainder",
            'bind' => [],
        ];
    }

    /**
     * @param array<string, mixed> $bind
     *
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildExpr(string $expr, array $bind): array
    {
        return [
            'condition' => $expr,
            'bind' => $bind,
        ];
    }

    /**
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildBetween(string $field, mixed $min, mixed $max): array
    {
        $id = strtr($field, '.', '_');
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        $min_key = $id . '_min';
        $max_key = $id . '_max';

        return [
            'condition' => "$normalizedField BETWEEN :$min_key AND :$max_key",
            'bind' => [$min_key => $min, $max_key => $max],
        ];
    }

    /**
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildNotBetween(string $field, mixed $min, mixed $max): array
    {
        $id = strtr($field, '.', '_');
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        $min_key = $id . '_min';
        $max_key = $id . '_max';

        return [
            'condition' => "$normalizedField NOT BETWEEN :$min_key AND :$max_key",
            'bind' => [$min_key => $min, $max_key => $max],
        ];
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildIn(string $field, array $values): array
    {
        if (!$values) {
            return [
                'condition' => 'FALSE',
                'bind' => [],
            ];
        }

        $id = str_replace('.', '_', $field);
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        if (is_int(current($values))) {
            return [
                'condition' => $normalizedField . ' IN (' . implode(', ', array_map('\intval', $values)) . ')',
                'bind' => [],
            ];
        } else {
            $bindKeys = [];
            $bind = [];
            foreach ($values as $k => $value) {
                $key = "{$id}_in_$k";
                $bindKeys[] = ":$key";
                $bind[$key] = $value;
            }

            return [
                'condition' => $normalizedField . ' IN (' . implode(', ', $bindKeys) . ')',
                'bind' => $bind,
            ];
        }
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return array{condition: string|null, bind: array<string, mixed>}
     */
    protected function buildNotIn(string $field, array $values): array
    {
        if (!$values) {
            return [
                'condition' => null,
                'bind' => [],
            ];
        }

        $id = str_replace('.', '_', $field);
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        if (is_int(current($values))) {
            return [
                'condition' => $normalizedField . ' NOT IN (' . implode(', ', array_map('\intval', $values)) . ')',
                'bind' => [],
            ];
        } else {
            $bindKeys = [];
            $bind = [];
            foreach ($values as $k => $value) {
                $key = "{$id}_not_in_$k";
                $bindKeys[] = ':' . $key;
                $bind[$key] = $value;
            }

            return [
                'condition' => $normalizedField . ' NOT IN (' . implode(', ', $bindKeys) . ')',
                'bind' => $bind,
            ];
        }
    }

    /**
     * @param array<string, mixed> $bind
     *
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildRaw(string $filter, array $bind): array
    {
        return [
            'condition' => $filter,
            'bind' => $bind,
        ];
    }

    /**
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildInset(string $field, string $value): array
    {
        $key = strtr($field, '.', '_');
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        return [
            'condition' => 'FIND_IN_SET(:' . $key . ', ' . $normalizedField . ')>0',
            'bind' => [$key => $value],
        ];
    }

    /**
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildNotInset(string $field, string $value): array
    {
        $key = strtr($field, '.', '_');
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        return [
            'condition' => 'FIND_IN_SET(:' . $key . ', ' . $normalizedField . ')=0',
            'bind' => [$key => $value],
        ];
    }

    /**
     * @param array<int, string> $fields
     *
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildLike(array $fields, string $value): array
    {
        if (count($fields) === 1) {
            $field = $fields[0];
            $key = strtr($field, '.', '_');
            $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

            return [
                'condition' => $normalizedField . ' LIKE :' . $key,
                'bind' => [$key => $value],
            ];
        } else {
            $conditions = [];
            $bind = [];
            $keyCounts = [];
            foreach ($fields as $field) {
                $baseKey = strtr($field, '.', '_');
                // Ensure unique parameter key by adding index if duplicate
                $keyCounts[$baseKey] = ($keyCounts[$baseKey] ?? 0) + 1;
                $key = $keyCounts[$baseKey] > 1 ? $baseKey . '_' . ($keyCounts[$baseKey] - 1) : $baseKey;
                $conditions[] = '[' . str_replace('.', '].[', $field) . ']' . ' LIKE :' . $key;
                $bind[$key] = $value;
            }

            return [
                'condition' => implode(' OR ', $conditions),
                'bind' => $bind,
            ];
        }
    }

    /**
     * @param array<int, string> $fields
     *
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildNotLike(array $fields, string $value): array
    {
        if (count($fields) === 1) {
            $field = $fields[0];
            $key = strtr($field, '.', '_');
            $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

            return [
                'condition' => $normalizedField . ' NOT LIKE :' . $key,
                'bind' => [$key => $value],
            ];
        } else {
            $conditions = [];
            $bind = [];
            $keyCounts = [];
            foreach ($fields as $field) {
                $baseKey = strtr($field, '.', '_');
                // Ensure unique parameter key by adding index if duplicate
                $keyCounts[$baseKey] = ($keyCounts[$baseKey] ?? 0) + 1;
                $key = $keyCounts[$baseKey] > 1 ? $baseKey . '_' . ($keyCounts[$baseKey] - 1) : $baseKey;
                $conditions[] = '[' . str_replace('.', '].[', $field) . ']' . ' NOT LIKE :' . $key;
                $bind[$key] = $value;
            }

            return [
                'condition' => implode(' AND ', $conditions),
                'bind' => $bind,
            ];
        }
    }

    /**
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildRegex(string $field, string $regex, string $flags): array
    {
        $key = strtr($field, '.', '_');
        $mode = (str_contains($flags, 'i') ? '' : 'BINARY ');
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        return [
            'condition' => $normalizedField . ' REGEXP ' . $mode . ':' . $key,
            'bind' => [$key => $regex],
        ];
    }

    /**
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildNotRegex(string $field, string $regex, string $flags): array
    {
        $key = strtr($field, '.', '_');
        $mode = (str_contains($flags, 'i') ? '' : 'BINARY ');
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        return [
            'condition' => $normalizedField . ' NOT REGEXP ' . $mode . ':' . $key,
            'bind' => [$key => $regex],
        ];
    }

    /**
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildNull(string $field): array
    {
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        return [
            'condition' => $normalizedField . ' IS NULL',
            'bind' => [],
        ];
    }

    /**
     * @return array{condition: string, bind: array<string, mixed>}
     */
    protected function buildNotNull(string $field): array
    {
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        return [
            'condition' => $normalizedField . ' IS NOT NULL',
            'bind' => [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getConditions(): array
    {
        return $this->build()['conditions'];
    }

    /**
     * {@inheritDoc}
     */
    public function getBind(?string $key = null): mixed
    {
        $bind = $this->build()['bind'];

        if ($key !== null) {
            return $bind[$key] ?? null;
        }

        return $bind;
    }

    /**
     * @param array<string, mixed> $bind
     * {@inheritDoc}
     */
    public function setBind(array $bind, bool $merge = true): void
    {
        $built = $this->build();
        $this->builtResult['bind'] = $merge ? array_merge($built['bind'], $bind) : $bind;
    }

}
