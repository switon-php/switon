<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Db\ClientInterface;
use Switon\Query\Table;
use ArrayIterator;

/**
 * Test cases for Query Iterator and JSON serialization.
 *
 * Tests IteratorAggregate and JsonSerializable interface implementations.
 *
 * @group query
 */
#[AllowMockObjectsWithoutExpectations]
class QueryIteratorTest extends QueryExecutionTest
{
    /**
     * Test that getIterator() returns ArrayIterator.
     */
    public function testGetIterator(): void
    {
        $expectedResults = [
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ];

        // Mock sharding manager
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn(['default' => ['users']]);

        // Mock named lookup
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn($expectedResults);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT * FROM [users]');

        // Configure methods that won't be called to avoid notices
        $this->dbClient->expects($this->never())
            ->method('executeInsert');
        $this->dbClient->expects($this->never())
            ->method('executeUpdate');
        $this->dbClient->expects($this->never())
            ->method('executeDelete');
        $this->dbClient->expects($this->never())
            ->method('delete');
        $this->dbClient->expects($this->never())
            ->method('update');

        $iterator = $this->query->getIterator();

        $this->assertInstanceOf(ArrayIterator::class, $iterator);
        $this->assertEquals($expectedResults, iterator_to_array($iterator));
    }

    /**
     * Test that Query can be iterated with foreach.
     */
    public function testQueryIsIterable(): void
    {
        $expectedResults = [
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ];

        // Mock sharding manager
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn(['default' => ['users']]);

        // Mock named lookup
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn($expectedResults);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT * FROM [users]');

        // Configure methods that won't be called to avoid notices
        $this->dbClient->expects($this->never())
            ->method('executeInsert');
        $this->dbClient->expects($this->never())
            ->method('executeUpdate');
        $this->dbClient->expects($this->never())
            ->method('executeDelete');
        $this->dbClient->expects($this->never())
            ->method('delete');
        $this->dbClient->expects($this->never())
            ->method('update');

        $results = [];
        foreach ($this->query as $row) {
            $results[] = $row;
        }

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Test that jsonSerialize() returns query results.
     */
    public function testJsonSerialize(): void
    {
        $expectedResults = [
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ];

        // Mock sharding manager
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn(['default' => ['users']]);

        // Mock named lookup
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn($expectedResults);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT * FROM [users]');

        // Configure methods that won't be called to avoid notices
        $this->dbClient->expects($this->never())
            ->method('executeInsert');
        $this->dbClient->expects($this->never())
            ->method('executeUpdate');
        $this->dbClient->expects($this->never())
            ->method('executeDelete');
        $this->dbClient->expects($this->never())
            ->method('delete');
        $this->dbClient->expects($this->never())
            ->method('update');

        $json = $this->query->jsonSerialize();

        $this->assertEquals($expectedResults, $json);
        $this->assertJson(json_encode($json));
    }

    /**
     * Test that Query can be JSON encoded.
     */
    public function testQueryCanBeJsonEncoded(): void
    {
        $expectedResults = [
            ['id' => 1, 'name' => 'John'],
        ];

        // Mock sharding manager
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn(['default' => ['users']]);

        // Mock named lookup
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn($expectedResults);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT * FROM [users]');

        // Configure methods that won't be called to avoid notices
        $this->dbClient->expects($this->never())
            ->method('executeInsert');
        $this->dbClient->expects($this->never())
            ->method('executeUpdate');
        $this->dbClient->expects($this->never())
            ->method('executeDelete');
        $this->dbClient->expects($this->never())
            ->method('delete');
        $this->dbClient->expects($this->never())
            ->method('update');

        $jsonString = json_encode($this->query);
        $decoded = json_decode($jsonString, true);

        $this->assertEquals($expectedResults, $decoded);
    }

    /**
     * Test getTable() returns table instance.
     */
    public function testGetTable(): void
    {
        $table = Table::of('users', 'default');
        $this->query->setTable($table);

        // Configure basic expectations for mock objects to avoid PHPUnit 12 notices
        // These mocks are not used in this test, but are created in setUp()
        $this->shardingManager->expects($this->never())
            ->method('multiple');
        $this->namedLookup->expects($this->never())
            ->method('by');
        $this->dbClient->expects($this->never())
            ->method('fetchAll');
        $this->dbClient->expects($this->never())
            ->method('buildSql');
        $this->dbClient->expects($this->never())
            ->method('getPrefix');
        $this->dbClient->expects($this->never())
            ->method('executeInsert');
        $this->dbClient->expects($this->never())
            ->method('executeUpdate');
        $this->dbClient->expects($this->never())
            ->method('executeDelete');
        $this->dbClient->expects($this->never())
            ->method('delete');
        $this->dbClient->expects($this->never())
            ->method('update');

        $result = $this->query->getTable();

        $this->assertInstanceOf(Table::class, $result);
        $this->assertEquals('users', $result->table);
        $this->assertEquals('default', $result->connection);
    }

}
