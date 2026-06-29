<?php

declare(strict_types=1);

namespace Switon\Query;

use Switon\Db\ClientInterface;

use function array_keys;
use function count;
use function implode;
use function preg_replace;
use function preg_replace_callback;
use function str_replace;
use function stripos;
use function strpbrk;
use function strtr;
use function substr;

use const SORT_ASC;

/**
 * Default SQL builder for {@see Query}.
 *
 * This class exists as an extension point:
 * - By convention, {@see SqlBuilderInterface} auto-maps to this class.
 * - Apps may override by binding {@see SqlBuilderInterface} to another class.
 *
 * @see \Switon\Query\SqlBuilderInterface
 * @see \Switon\Query\Query
 * @see \Switon\Query\Clauses
 */
class SqlBuilder implements SqlBuilderInterface
{
    public function buildSql(ClientInterface $client, string $table, Clauses $clauses): string
    {
        $params = [];
        if ($clauses->distinct) {
            $params['distinct'] = true;
        }

        $params['fields'] = $clauses->fields ?? '*';

        $table = $client->getPrefix() . $table;
        $table = '[' . str_replace('.', '].[', $table) . ']';
        $params['from'] = $clauses->alias ? "$table AS [$clauses->alias]" : $table;

        $params['join'] = $this->buildJoinSql($client, $clauses->joins);

        $conditions = $clauses->conditions;
        if (count($conditions) === 1) {
            $params['where'] = $conditions[0];
        } elseif ($conditions !== []) {
            $wheres = [];
            foreach ($conditions as $v) {
                $wheres[] = stripos($v, ' or ') ? "($v)" : $v;
            }
            $params['where'] = implode(' AND ', $wheres);
        }

        if ($clauses->group) {
            $params['group'] = $this->buildGroup($clauses->group);
        }

        if ($clauses->having !== null) {
            $params['having'] = $clauses->having;
        }

        if ($clauses->order) {
            $params['order'] = $this->buildOrder($clauses->order);
        }

        if (($clauses->limit ?? 0) > 0) {
            $params['limit'] = $clauses->limit;
        }

        if ($clauses->offset > 0) {
            $params['offset'] = $clauses->offset;
        }

        if ($clauses->forUpdate) {
            $params['forUpdate'] = true;
        }

        $sql = $client->buildSql($params);
        $sql = $this->translateField2Columns($sql, $clauses->columnMap);

        // Compatible with other SQL syntax: ":k:" → ":k"
        $replaces = [];
        foreach ($clauses->bind as $key => $_) {
            $replaces[':' . $key . ':'] = ':' . $key;
        }
        return strtr($sql, $replaces);
    }

    /**
     * @param array<int, array{0: Table|string, 1: string|null, 2: string|null, 3: string|null}> $joins
     */
    protected function buildJoinSql(ClientInterface $client, array $joins): string
    {
        $joinSQL = '';

        foreach ($joins as [$joinTable, $joinCondition, $joinAlias, $joinType]) {
            if ($joinType !== null) {
                $joinSQL .= ' ' . $joinType;
            }

            $joinTable = $client->getPrefix() . $joinTable;
            $joinTable = '[' . str_replace('.', '].[', $joinTable) . ']';
            $joinSQL .= " JOIN $joinTable";

            if ($joinAlias !== null) {
                $joinSQL .= ' AS [' . $joinAlias . ']';
            }

            if ($joinCondition) {
                $joinSQL .= ' ON ' . $joinCondition;
            }
        }
        return $joinSQL;
    }

    /**
     * @param array<int|string, string> $group
     */
    protected function buildGroup(array $group): string
    {
        $r = '';
        foreach ($group as $item) {
            $item = (string)$item;
            if (strpbrk($item, '[(') === false) {
                $r .= preg_replace('#\\w+#', '[\\0]', $item) . ', ';
            } else {
                $r .= $item . ', ';
            }
        }

        return substr($r, 0, -2);
    }

    /**
     * @param array<string, int|string> $order
     */
    protected function buildOrder(array $order): string
    {
        $r = '';

        foreach ($order as $field => $v) {
            $field = (string)$field;
            $type = $v === SORT_ASC ? 'ASC' : 'DESC';

            if (strpbrk($field, '[(') === false) {
                $r .= '[' . str_replace('.', '].[', $field) . '] ' . $type . ', ';
            } else {
                $r .= "$field $type, ";
            }
        }

        return substr($r, 0, -2);
    }

    /**
     * @param array<string, string>|null $columnMap
     */
    protected function translateField2Columns(string $sql, ?array $columnMap): string
    {
        if ($columnMap === null || $columnMap === []) {
            return $sql;
        }

        $pattern = '#\\[(' . implode('|', array_keys($columnMap)) . ')]#';
        return preg_replace_callback($pattern, static fn (array $matches): string => '[' . $columnMap[$matches[1]] . ']', $sql);
    }
}
