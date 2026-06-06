<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Switon\Query\Table;
use Switon\Query\Tests\TestCase;

/**
 * Test cases for Table class.
 *
 * @group query
 */
class TableTest extends TestCase
{
    /**
     * Test that Table constructor sets table and connection.
     */
    public function testConstructor(): void
    {
        $table = new Table('users', 'default');

        $this->assertEquals('users', $table->table);
        $this->assertEquals('default', $table->connection);
    }

    /**
     * Test that Table::of() creates instance with default connection.
     */
    public function testOfWithDefaultConnection(): void
    {
        $table = Table::of('users');

        $this->assertEquals('users', $table->table);
        $this->assertEquals('default', $table->connection);
    }

    /**
     * Test that Table::of() creates instance with custom connection.
     */
    public function testOfWithCustomConnection(): void
    {
        $table = Table::of('users', 'mysql');

        $this->assertEquals('users', $table->table);
        $this->assertEquals('mysql', $table->connection);
    }

    /**
     * Test that Table instances are equal when table and connection match.
     */
    public function testEquality(): void
    {
        $table1 = Table::of('users', 'default');
        $table2 = Table::of('users', 'default');

        $this->assertEquals($table1->table, $table2->table);
        $this->assertEquals($table1->connection, $table2->connection);
    }
}
