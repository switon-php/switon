<?php

declare(strict_types=1);

namespace Switon\Query;

use Switon\Core\Exception\MisuseException;

use function array_column;
use function array_keys;
use function array_sum;
use function current;
use function implode;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function key;
use function max;
use function min;
use function strcmp;
use function usort;

/**
 * Default implementation of <code>ShardMergerInterface</code>.
 *
 * Use when query results must be merged across shards in application memory.
 *
 * @see \Switon\Query\ShardMergerInterface
 * @see \Switon\Query\Query
 * @see \Switon\Query\ShardingContextInterface
 */
class ShardMerger implements ShardMergerInterface
{
    /**
     * {@inheritDoc}
     */
    public function sort(array &$ar, array $sort): array
    {
        usort(
            $ar,
            static function ($a, $b) use ($sort) {
                foreach ($sort as $k => $v) {
                    $field = is_int($k) ? $v : $k;

                    $first = $a[$field];
                    $second = $b[$field];

                    $r = is_string($first) ? strcmp($first, $second) : $first - $second;
                    if ($r > 0) {
                        return (is_int($k) || $v === SORT_ASC || $v === 'ASC' || $v === 'asc') ? 1 : -1;
                    } elseif ($r < 0) {
                        return (is_int($k) || $v === SORT_ASC || $v === 'ASC' || $v === 'asc') ? -1 : 1;
                    }
                }
                return 0;
            }
        );

        return $ar;
    }

    /**
     * {@inheritDoc}
     */
    public function indexBy(array $ar, mixed $index): array
    {
        $rows = [];
        if (is_scalar($index)) {
            foreach ($ar as $row) {
                $rows[$row[$index]] = $row;
            }
        } elseif (is_array($index)) {
            $k = key($index);
            $v = current($index);
            foreach ($ar as $row) {
                $rows[$row[$k]] = $row[$v];
            }
        } else {
            foreach ($ar as $row) {
                $rows[$index($row)] = $row;
            }
        }

        return $rows;
    }

    /**
     * {@inheritDoc}
     */
    public function aggregate(array $rows, array $aggs, array $group = []): array
    {
        if (!$rows) {
            return [];
        }

        $grouped_rows = [];
        foreach ($rows as $item) {
            $key = '';
            foreach ($group as $g) {
                if ($key === '') {
                    $key = $item[$g];
                } else {
                    $key .= ':' . $item[$g];
                }
            }
            $grouped_rows[$key][] = $item;
        }

        $result = [];
        foreach ($grouped_rows as $v) {
            $row = [];

            foreach ($group as $gk => $gv) {
                $field = is_int($gk) ? $gv : $gk;
                $row[$field] = $v[0][$field];
            }

            foreach ($aggs as $field => $agg) {
                if ($agg === 'MAX') {
                    $values = array_column($v, $field);
                    $max = max($values);
                    $row[$field] = $max === false ? null : $max;
                } elseif ($agg === 'MIN') {
                    $values = array_column($v, $field);
                    $max = min($values);
                    $row[$field] = $max === false ? null : $max;
                } elseif ($agg === 'COUNT') {
                    $values = array_column($v, $field);
                    $row[$field] = array_sum($values);
                } elseif ($agg === 'SUM') {
                    $values = array_column($v, $field);
                    $row[$field] = array_sum($values);
                } elseif ($agg === 'AVG') {
                    $sum_field = $field . '_sum';
                    if (!isset($v[0][$sum_field])) {
                        MisuseException::raise('Field "{sum_field}" does not exist, available: {fields}.', ['sum_field' => $sum_field, 'fields' => implode(', ', array_keys($v[0]))]);
                    }
                    $sum = array_sum(array_column($v, $sum_field));

                    $count_field = $field . '_count';
                    if (!isset($v[0][$count_field])) {
                        MisuseException::raise(
                            '"{count_field}" not in "{fields}"',
                            ['count_field' => $count_field, 'fields' => implode(',', array_keys($v[0]))]
                        );
                    }
                    $count = array_sum(array_column($v, $count_field));

                    $row[$field] = $count ? $sum / $count : null;
                } else {
                    /** @noinspection PhpExpressionResultUnusedInspection */
                    null;
                }
            }

            $result[] = $row;
        }

        return $result;
    }
}
