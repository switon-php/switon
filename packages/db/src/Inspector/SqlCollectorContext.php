<?php

declare(strict_types=1);

namespace Switon\Db\Inspector;

/**
 * Request/coroutine-local storage for SQL collector counters and timeline rows.
 *
 * @see \Switon\Db\Inspector\SqlCollector
 */
class SqlCollectorContext
{
    /** @var array<string, int> Prepared SQL text to occurrence count. */
    public array $prepared = [];

    /** @var list<array<string, mixed>> Executed SQL timeline entries. */
    public array $executed = [];

    /** Current executed entry count. */
    public int $count = 0;
}
