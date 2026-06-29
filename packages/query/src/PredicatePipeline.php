<?php

declare(strict_types=1);

namespace Switon\Query;

use Switon\Core\Attribute\Autowired;
use Switon\Db\Exception\UnknownOperatorException;
use Switon\Query\Exception\InvalidFilterException;

use function array_map;
use function array_merge;
use function count;
use function current;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function method_exists;
use function preg_match;
use function preg_replace;
use function preg_split;
use function str_contains;
use function str_pad;
use function str_replace;
use function strtr;
use function substr;
use function trim;

/**
 * Default implementation of {@see PredicatePipelineInterface}.
 *
 * @phpstan-type Predicate array<string, mixed>
 * @phpstan-type CompileResult array{condition: string|null, bind: array<string, mixed>}
 * Stateless: predicate IR is owned by Query ({@see Clauses::$predicates}).
 */
class PredicatePipeline implements PredicatePipelineInterface
{
    /**
     * Predicate type to compiler method mapping.
     *
     * @var array<string, string>
     */
    #[Autowired] protected array $compilers = [
        'eq' => 'compileEqPredicate',
        'cmp' => 'compileCmpPredicate',
        'mod' => 'compileModPredicate',
        'expr' => 'compileExprPredicate',
        'between' => 'compileBetweenPredicate',
        'not_between' => 'compileNotBetweenPredicate',
        'in' => 'compileInPredicate',
        'not_in' => 'compileNotInPredicate',
        'inset' => 'compileInsetPredicate',
        'contains' => 'compileContainsPredicate',
        'starts_with' => 'compileStartsWithPredicate',
        'ends_with' => 'compileEndsWithPredicate',
        'null' => 'compileNullPredicate',
        'not_null' => 'compileNotNullPredicate',
        'regex' => 'compileRegexPredicate',
        'not_regex' => 'compileNotRegexPredicate',
        'raw' => 'compileRawPredicate',
    ];

    /**
     * {@inheritDoc}
     *
     * @param array<string|int, mixed> $filters
     *
     * @return list<Predicate>
     */
    public function parse(array $filters): array
    {
        $predicates = [];

        foreach ($filters as $filter => $value) {
            if (is_int($filter)) {
                $predicates[] = [
                    'type' => 'expr',
                    'expr' => $value,
                    'bind' => [],
                ];
                continue;
            }

            if (is_array($value)) {
                if (preg_match('#([~@!<>=%]+)$#', $filter, $match)) {
                    $operator = $match[1];
                    $field = substr($filter, 0, -strlen($operator));
                    if ($operator === '~=') {
                        if (count($value) !== 2) {
                            InvalidFilterException::raise('Range filter "{filter}" requires exactly 2 values [min, max], got {count}', ['filter' => $filter, 'count' => count($value)]);
                        }
                        $predicates[] = ['type' => 'between', 'field' => $field, 'min' => $value[0], 'max' => $value[1]];
                    } elseif ($operator === '!=') {
                        $predicates[] = ['type' => 'not_in', 'field' => $field, 'values' => $value];
                    } elseif ($operator === '=') {
                        $predicates[] = ['type' => 'in', 'field' => $field, 'values' => $value];
                    } elseif ($operator === '%=') {
                        $predicates[] = ['type' => 'mod', 'field' => $field, 'divisor' => $value[0], 'remainder' => $value[1]];
                    } else {
                        InvalidFilterException::raise('Unknown filter operator "{op}" in "{filter}", supported: ~=, !=, =, %=', ['op' => $operator, 'filter' => $filter]);
                    }
                } elseif (!$value || isset($value[0])) {
                    $predicates[] = ['type' => 'in', 'field' => $filter, 'values' => $value];
                } else {
                    continue;
                }
                continue;
            }

            // Comma-separated fields with optional operator (e.g., 'field1,field2*=')
            if (str_contains($filter, ',') && preg_match('#^([\\w,.]+)([<>=!^$*~,@?]*)$#', $filter, $matches) === 1) {
                [, $fields, $operator] = $matches;

                if (str_contains($operator, '?')) {
                    $value = is_string($value) ? trim($value) : $value;
                    if ($value === '' || $value === null) {
                        continue;
                    }
                    $operator = substr($operator, 0, -1);
                }

                if ($operator === '') {
                    InvalidFilterException::raise('Multi-field filter "{filter}" requires operator suffix (^=, $=, or *=)', ['filter' => $filter]);
                }

                $fieldArray = preg_split('#\\s*,\\s*#', $fields, -1, PREG_SPLIT_NO_EMPTY);

                if ($operator === '^=') {
                    $predicates[] = ['type' => 'starts_with', 'fields' => $fieldArray, 'value' => $value, 'not' => false, 'length' => null];
                } elseif ($operator === '$=') {
                    $predicates[] = ['type' => 'ends_with', 'fields' => $fieldArray, 'value' => $value, 'not' => false];
                } elseif ($operator === '*=') {
                    $predicates[] = ['type' => 'contains', 'fields' => $fieldArray, 'value' => $value, 'not' => false, 'length' => null];
                } elseif (count($fieldArray) === 1) {
                    $field = $fieldArray[0];
                    if (in_array($operator, ['=', '~=', '!=', '>', '>=', '<', '<='], true)) {
                        $predicates[] = ['type' => 'cmp', 'field' => $field, 'operator' => $operator, 'value' => $value];
                    } elseif ($operator === ',=') {
                        $predicates[] = ['type' => 'inset', 'field' => $field, 'value' => $value, 'not' => false];
                    } else {
                        InvalidFilterException::raise('Unknown filter operator "{op}" in "{filter}", supported: =, ~=, !=, >, >=, <, <=, ,=', ['op' => $operator, 'filter' => $filter]);
                    }
                } else {
                    InvalidFilterException::raise('Multi-field filter "{filter}" only supports ^=, $=, *= operators, got "{op}"', ['filter' => $filter, 'op' => $operator]);
                }

                continue;
            }

            if (preg_match('#^([\\w.]+)([<>=!^$*~,@?]*)$#', $filter, $matches) === 1) {
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
                    if ($operator === '=' && $value === null) {
                        $predicates[] = ['type' => 'eq', 'field' => $field, 'value' => $value];
                    } elseif ($operator === '=') {
                        $predicates[] = ['type' => 'eq', 'field' => $field, 'value' => $value];
                    } else {
                        $predicates[] = ['type' => 'cmp', 'field' => $field, 'operator' => $operator, 'value' => $value];
                    }
                } elseif ($operator === '^=') {
                    $predicates[] = ['type' => 'starts_with', 'fields' => [$field], 'value' => $value, 'not' => false, 'length' => null];
                } elseif ($operator === '$=') {
                    $predicates[] = ['type' => 'ends_with', 'fields' => [$field], 'value' => $value, 'not' => false];
                } elseif ($operator === '*=') {
                    $predicates[] = ['type' => 'contains', 'fields' => [$field], 'value' => $value, 'not' => false, 'length' => null];
                } elseif ($operator === ',=') {
                    $predicates[] = ['type' => 'inset', 'field' => $field, 'value' => $value, 'not' => false];
                } else {
                    InvalidFilterException::raise('Unknown filter operator "{op}" in "{filter}", supported: =, ~=, !=, >, >=, <, <=, ^=, $=, *=, ,=', ['op' => $operator, 'filter' => $filter]);
                }

                continue;
            }

            InvalidFilterException::raise('Invalid filter key format "{filter}", expected: field, field>=, field^=, etc.', ['filter' => $filter]);
        }

        return $predicates;
    }

    /**
     * @param list<Predicate> $predicates
     *
     * @return array{conditions: list<string>, bind: array<string, mixed>}
     */
    public function compile(array $predicates): array
    {
        $conditions = [];
        $bind = [];

        foreach ($predicates as $predicate) {
            $result = $this->compilePredicate($predicate);
            if ($result['condition'] !== null) {
                $conditions[] = $result['condition'];
            }
            /** @noinspection SlowArrayOperationsInLoopInspection */
            $bind = array_merge($bind, $result['bind']);
        }

        return [
            'conditions' => $conditions,
            'bind' => $bind,
        ];
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compilePredicate(array $predicate): array
    {
        $type = $predicate['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return ['condition' => null, 'bind' => []];
        }

        $compiler = $this->compilers[$type] ?? null;
        if (is_string($compiler) && $compiler !== '' && method_exists($this, $compiler)) {
            return $this->{$compiler}($predicate);
        }

        return $this->compileLegacyPredicate($type, $predicate);
    }

    /**
     * Legacy predicate compiler dispatch used as fallback for invalid mappings.
     *
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileLegacyPredicate(string $type, array $predicate): array
    {
        return match ($type) {
            'eq' => $this->compileEqPredicate($predicate),
            'cmp' => $this->compileCmpPredicate($predicate),
            'mod' => $this->compileModPredicate($predicate),
            'expr' => $this->compileExprPredicate($predicate),
            'between' => $this->compileBetweenPredicate($predicate),
            'not_between' => $this->compileNotBetweenPredicate($predicate),
            'in' => $this->compileInPredicate($predicate),
            'not_in' => $this->compileNotInPredicate($predicate),
            'inset' => $this->compileInsetPredicate($predicate),
            'contains' => $this->compileContainsPredicate($predicate),
            'starts_with' => $this->compileStartsWithPredicate($predicate),
            'ends_with' => $this->compileEndsWithPredicate($predicate),
            'null' => $this->compileNullPredicate($predicate),
            'not_null' => $this->compileNotNullPredicate($predicate),
            'regex' => $this->compileRegexPredicate($predicate),
            'not_regex' => $this->compileNotRegexPredicate($predicate),
            'raw' => $this->compileRawPredicate($predicate),
            default => ['condition' => null, 'bind' => []],
        };
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileEqPredicate(array $predicate): array
    {
        return $this->compileEq((string)$predicate['field'], $predicate['value']);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileCmpPredicate(array $predicate): array
    {
        return $this->compileCmp((string)$predicate['field'], (string)$predicate['operator'], $predicate['value']);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileModPredicate(array $predicate): array
    {
        return $this->compileMod((string)$predicate['field'], (int)$predicate['divisor'], (int)$predicate['remainder']);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileExprPredicate(array $predicate): array
    {
        return $this->compileExpr((string)$predicate['expr'], is_array($predicate['bind'] ?? null) ? $predicate['bind'] : []);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileBetweenPredicate(array $predicate): array
    {
        return $this->compileBetween((string)$predicate['field'], $predicate['min'], $predicate['max']);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileNotBetweenPredicate(array $predicate): array
    {
        return $this->compileNotBetween((string)$predicate['field'], $predicate['min'], $predicate['max']);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileInPredicate(array $predicate): array
    {
        $values = $predicate['values'] ?? [];
        return $this->compileIn((string)$predicate['field'], is_array($values) ? $values : []);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileNotInPredicate(array $predicate): array
    {
        $values = $predicate['values'] ?? [];
        return $this->compileNotIn((string)$predicate['field'], is_array($values) ? $values : []);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileInsetPredicate(array $predicate): array
    {
        return ($predicate['not'] ?? false)
            ? $this->compileNotInset((string)$predicate['field'], (string)$predicate['value'])
            : $this->compileInset((string)$predicate['field'], (string)$predicate['value']);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileContainsPredicate(array $predicate): array
    {
        $fields = $predicate['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }

        $value = '%' . $predicate['value'] . '%';
        return ($predicate['not'] ?? false)
            ? $this->compileNotLike($fields, $value)
            : $this->compileLike($fields, $value);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileStartsWithPredicate(array $predicate): array
    {
        $fields = $predicate['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }

        $value = $predicate['length'] === null
            ? ($predicate['value'] . '%')
            : str_pad((string)$predicate['value'], (int)$predicate['length'], '_');

        return ($predicate['not'] ?? false)
            ? $this->compileNotLike($fields, $value)
            : $this->compileLike($fields, $value);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileEndsWithPredicate(array $predicate): array
    {
        $fields = $predicate['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }

        $value = '%' . $predicate['value'];
        return ($predicate['not'] ?? false)
            ? $this->compileNotLike($fields, $value)
            : $this->compileLike($fields, $value);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileNullPredicate(array $predicate): array
    {
        return $this->compileNull((string)$predicate['field']);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileNotNullPredicate(array $predicate): array
    {
        return $this->compileNotNull((string)$predicate['field']);
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileRegexPredicate(array $predicate): array
    {
        return $this->compileRegex((string)$predicate['field'], (string)$predicate['regex'], (string)($predicate['flags'] ?? ''));
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileNotRegexPredicate(array $predicate): array
    {
        return $this->compileNotRegex((string)$predicate['field'], (string)$predicate['regex'], (string)($predicate['flags'] ?? ''));
    }

    /**
     * @param Predicate $predicate
     *
     * @return CompileResult
     */
    protected function compileRawPredicate(array $predicate): array
    {
        $bind = $predicate['bind'] ?? [];
        return $this->compileRaw((string)$predicate['filter'], is_array($bind) ? $bind : []);
    }

    /**
     * @return CompileResult
     */
    protected function compileEq(string $field, mixed $value): array
    {
        $normalizedField = preg_replace('#\\w+#', '[\\0]', $field);

        if ($value === null) {
            return ['condition' => $normalizedField . ' IS NULL', 'bind' => []];
        }

        $bindKey = str_contains($field, '.') ? strtr($field, '.', '_') : $field;
        return ['condition' => "$normalizedField=:$bindKey", 'bind' => [$bindKey => $value]];
    }

    /**
     * @return CompileResult
     */
    protected function compileCmp(string $field, string $operator, mixed $value): array
    {
        if ($operator === '=') {
            return $this->compileEq($field, $value);
        }

        $bindKey = str_contains($field, '.') ? strtr($field, '.', '_') : $field;
        $normalizedField = preg_replace('#\\w+#', '[\\0]', $field);

        if ($operator === '~=') {
            if ($value === 0 || $value === 0.0) {
                return ['condition' => "$normalizedField IS NULL OR $normalizedField=0", 'bind' => []];
            }
            if ($value === '') {
                return ['condition' => "$normalizedField IS NULL OR $normalizedField=''", 'bind' => []];
            }
            return ['condition' => $normalizedField . '=:' . $bindKey, 'bind' => [$bindKey => $value]];
        }

        if ($operator === '!=') {
            if ($value === null) {
                return ['condition' => $normalizedField . ' IS NOT NULL', 'bind' => []];
            }
            return ['condition' => $normalizedField . '!=' . ':' . $bindKey, 'bind' => [$bindKey => $value]];
        }

        if (in_array($operator, ['>', '>=', '<', '<='], true)) {
            return ['condition' => $normalizedField . $operator . ':' . $bindKey, 'bind' => [$bindKey => $value]];
        }

        UnknownOperatorException::raise('Unknown query operator "{operator}" in condition', ['operator' => $operator]);
    }

    /**
     * @return CompileResult
     */
    protected function compileMod(string $field, int $divisor, int $remainder): array
    {
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';
        return ['condition' => "$normalizedField%$divisor=$remainder", 'bind' => []];
    }

    /**
     * @param array<string, mixed> $bind
     *
     * @return CompileResult
     */
    protected function compileExpr(string $expr, array $bind): array
    {
        return ['condition' => $expr, 'bind' => $bind];
    }

    /**
     * @return CompileResult
     */
    protected function compileBetween(string $field, mixed $min, mixed $max): array
    {
        if ($min === null || $min === '') {
            if ($max === null || $max === '') {
                return ['condition' => null, 'bind' => []];
            }
            return $this->compileCmp($field, '<=', $max);
        }
        if ($max === null || $max === '') {
            return $this->compileCmp($field, '>=', $min);
        }

        $id = strtr($field, '.', '_');
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        $minKey = $id . '_min';
        $maxKey = $id . '_max';

        return [
            'condition' => "$normalizedField BETWEEN :$minKey AND :$maxKey",
            'bind' => [$minKey => $min, $maxKey => $max],
        ];
    }

    /**
     * @return CompileResult
     */
    protected function compileNotBetween(string $field, mixed $min, mixed $max): array
    {
        if ($min === null || $min === '') {
            if ($max === null || $max === '') {
                return ['condition' => null, 'bind' => []];
            }
            return $this->compileCmp($field, '>', $max);
        }
        if ($max === null || $max === '') {
            return $this->compileCmp($field, '<', $min);
        }

        $id = strtr($field, '.', '_');
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        $minKey = $id . '_min';
        $maxKey = $id . '_max';

        return [
            'condition' => "$normalizedField NOT BETWEEN :$minKey AND :$maxKey",
            'bind' => [$minKey => $min, $maxKey => $max],
        ];
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return CompileResult
     */
    protected function compileIn(string $field, array $values): array
    {
        if (!$values) {
            return ['condition' => 'FALSE', 'bind' => []];
        }

        $id = str_replace('.', '_', $field);
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        if (is_int(current($values))) {
            return ['condition' => $normalizedField . ' IN (' . implode(', ', array_map('\\intval', $values)) . ')', 'bind' => []];
        }

        $bindKeys = [];
        $bind = [];
        foreach ($values as $k => $value) {
            $key = "{$id}_in_$k";
            $bindKeys[] = ":$key";
            $bind[$key] = $value;
        }

        return ['condition' => $normalizedField . ' IN (' . implode(', ', $bindKeys) . ')', 'bind' => $bind];
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return CompileResult
     */
    protected function compileNotIn(string $field, array $values): array
    {
        if (!$values) {
            return ['condition' => null, 'bind' => []];
        }

        $id = str_replace('.', '_', $field);
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';

        if (is_int(current($values))) {
            return ['condition' => $normalizedField . ' NOT IN (' . implode(', ', array_map('\\intval', $values)) . ')', 'bind' => []];
        }

        $bindKeys = [];
        $bind = [];
        foreach ($values as $k => $value) {
            $key = "{$id}_not_in_$k";
            $bindKeys[] = ':' . $key;
            $bind[$key] = $value;
        }

        return ['condition' => $normalizedField . ' NOT IN (' . implode(', ', $bindKeys) . ')', 'bind' => $bind];
    }

    /**
     * @param array<string, mixed> $bind
     *
     * @return CompileResult
     */
    protected function compileRaw(string $filter, array $bind): array
    {
        return ['condition' => $filter, 'bind' => $bind];
    }

    /**
     * @return CompileResult
     */
    protected function compileInset(string $field, string $value): array
    {
        $key = strtr($field, '.', '_');
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';
        return ['condition' => 'FIND_IN_SET(:' . $key . ', ' . $normalizedField . ')>0', 'bind' => [$key => $value]];
    }

    /**
     * @return CompileResult
     */
    protected function compileNotInset(string $field, string $value): array
    {
        $key = strtr($field, '.', '_');
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';
        return ['condition' => 'FIND_IN_SET(:' . $key . ', ' . $normalizedField . ')=0', 'bind' => [$key => $value]];
    }

    /**
     * @param array<int, string> $fields
     *
     * @return CompileResult
     */
    protected function compileLike(array $fields, string $value): array
    {
        if ($value === '') {
            return ['condition' => null, 'bind' => []];
        }

        if (count($fields) === 1) {
            $field = $fields[0];
            $key = strtr($field, '.', '_');
            $normalizedField = '[' . str_replace('.', '].[', $field) . ']';
            return ['condition' => $normalizedField . ' LIKE :' . $key, 'bind' => [$key => $value]];
        }

        $conditions = [];
        $bind = [];
        $keyCounts = [];
        foreach ($fields as $field) {
            $baseKey = strtr($field, '.', '_');
            $keyCounts[$baseKey] = ($keyCounts[$baseKey] ?? 0) + 1;
            $key = $keyCounts[$baseKey] > 1 ? $baseKey . '_' . ($keyCounts[$baseKey] - 1) : $baseKey;
            $conditions[] = '[' . str_replace('.', '].[', $field) . ']' . ' LIKE :' . $key;
            $bind[$key] = $value;
        }

        return ['condition' => implode(' OR ', $conditions), 'bind' => $bind];
    }

    /**
     * @param array<int, string> $fields
     *
     * @return CompileResult
     */
    protected function compileNotLike(array $fields, string $value): array
    {
        if ($value === '') {
            return ['condition' => null, 'bind' => []];
        }

        if (count($fields) === 1) {
            $field = $fields[0];
            $key = strtr($field, '.', '_');
            $normalizedField = '[' . str_replace('.', '].[', $field) . ']';
            return ['condition' => $normalizedField . ' NOT LIKE :' . $key, 'bind' => [$key => $value]];
        }

        $conditions = [];
        $bind = [];
        $keyCounts = [];
        foreach ($fields as $field) {
            $baseKey = strtr($field, '.', '_');
            $keyCounts[$baseKey] = ($keyCounts[$baseKey] ?? 0) + 1;
            $key = $keyCounts[$baseKey] > 1 ? $baseKey . '_' . ($keyCounts[$baseKey] - 1) : $baseKey;
            $conditions[] = '[' . str_replace('.', '].[', $field) . ']' . ' NOT LIKE :' . $key;
            $bind[$key] = $value;
        }

        return ['condition' => implode(' AND ', $conditions), 'bind' => $bind];
    }

    /**
     * @return CompileResult
     */
    protected function compileNull(string $field): array
    {
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';
        return ['condition' => $normalizedField . ' IS NULL', 'bind' => []];
    }

    /**
     * @return CompileResult
     */
    protected function compileNotNull(string $field): array
    {
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';
        return ['condition' => $normalizedField . ' IS NOT NULL', 'bind' => []];
    }

    /**
     * @return CompileResult
     */
    protected function compileRegex(string $field, string $regex, string $flags): array
    {
        $key = strtr($field, '.', '_');
        $mode = (str_contains($flags, 'i') ? '' : 'BINARY ');
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';
        return ['condition' => $normalizedField . ' REGEXP ' . $mode . ':' . $key, 'bind' => [$key => $regex]];
    }

    /**
     * @return CompileResult
     */
    protected function compileNotRegex(string $field, string $regex, string $flags): array
    {
        $key = strtr($field, '.', '_');
        $mode = (str_contains($flags, 'i') ? '' : 'BINARY ');
        $normalizedField = '[' . str_replace('.', '].[', $field) . ']';
        return ['condition' => $normalizedField . ' NOT REGEXP ' . $mode . ':' . $key, 'bind' => [$key => $regex]];
    }
}
