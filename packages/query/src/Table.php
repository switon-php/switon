<?php

declare(strict_types=1);

namespace Switon\Query;

/**
 * Value object for a physical table target and connection name.
 *
 * Use when passing logical or shard-resolved table targets into query construction and execution methods.
 *
 * @see \Switon\Query\Query
 * @see \Switon\Query\QueryInterface
 */
class Table
{
    /**
     * @param string $table Table name
     * @param string $connection Connection name (default: 'default')
     */
    public function __construct(
        public string $table,
        public string $connection = 'default'
    ) {
    }

    /**
     * Creates a Table instance.
     *
     * @param string $table Table name
     * @param string $connection Connection name (default: 'default')
     *
     * @return static
     */
    public static function of(string $table, string $connection = 'default'): static
    {
        return new static($table, $connection);
    }
}
