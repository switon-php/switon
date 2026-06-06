<?php

declare(strict_types=1);

namespace Switon\Query;

use Switon\Db\ClientInterface;

/**
 * Compiles {@see Clauses} into SQL for a specific database client.
 *
 * Use to customize SQL generation without changing {@see Query}.
 * Default implementation is {@see SqlBuilder}.
 *
 * Guidance: Expect one physical table name per build; shard expansion happens before this boundary.
 *
 * @see \Switon\Query\SqlBuilder
 * @see \Switon\Query\Query
 * @see \Switon\Query\Clauses
 */
interface SqlBuilderInterface
{
    /**
     * Build SQL from clauses for one physical table.
     *
     * @param ClientInterface $client Database client (prefix + SQL dialect helpers)
     * @param string $table Physical table name (may include shard suffix)
     * @param Clauses $clauses Query clauses (user-built inputs)
     */
    public function buildSql(ClientInterface $client, string $table, Clauses $clauses): string;
}
