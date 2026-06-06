<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Switon\Query\Table;
use Switon\Query\Tests\TestCase;

/**
 * Extended test cases for Table class.
 *
 * Tests Table functionality and edge cases.
 *
 * @group query
 */
class TableExtendedTest extends TestCase
{
    /**
     * Test that Table constructor works correctly.
     */
    public function testConstructor(): void
    {
        $table = new Table('users');
        $this->assertInstanceOf(Table::class, $table);
        $this->assertSame('users', $table->table);
        $this->assertSame('default', $table->connection);
    }

    /**
     * Test that Table constructor works with custom connection.
     */
    public function testConstructorWithCustomConnection(): void
    {
        $table = new Table('users', 'custom_connection');
        $this->assertInstanceOf(Table::class, $table);
        $this->assertSame('users', $table->table);
        $this->assertSame('custom_connection', $table->connection);
    }

    /**
     * Test that Table::of() creates instance with default connection.
     */
    public function testOfWithDefaultConnection(): void
    {
        $table = Table::of('users');
        $this->assertInstanceOf(Table::class, $table);
        $this->assertSame('users', $table->table);
        $this->assertSame('default', $table->connection);
    }

    /**
     * Test that Table::of() creates instance with custom connection.
     */
    public function testOfWithCustomConnection(): void
    {
        $table = Table::of('users', 'custom_connection');
        $this->assertInstanceOf(Table::class, $table);
        $this->assertSame('users', $table->table);
        $this->assertSame('custom_connection', $table->connection);
    }

    /**
     * Test that Table properties can be accessed.
     */
    public function testPropertyAccess(): void
    {
        $table = new Table('posts', 'test_connection');
        $this->assertSame('posts', $table->table);
        $this->assertSame('test_connection', $table->connection);
    }
}
