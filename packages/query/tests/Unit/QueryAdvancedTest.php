<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Db\ClientInterface;
use Switon\Query\Table;
use Exception;

/**
 * Test cases for Query advanced scenarios.
 *
 * Tests complex query scenarios including JOINs, sharding, column mapping, etc.
 */
#[AllowMockObjectsWithoutExpectations]
class QueryAdvancedTest extends QueryExecutionTest
{
    /**
     * Test that getSql() generates SQL string.
     *
     * User perspective: I want to see the SQL that will be executed for debugging/logging.
     * This tests the public API getSql() which users may call for debugging purposes.
     */
    public function testGetSql(): void
    {
        // Setup mocks with explicit expectations
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn(['default' => ['users']]);

        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);
        // Configure other methods that won't be called
        $this->namedLookup->expects($this->never())
            ->method('names');

        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->with($this->isArray())
            ->willReturn('SELECT * FROM [users]');

        // Configure methods that won't be called to avoid notices
        $this->dbClient->expects($this->never())
            ->method('fetchAll');
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

        // User's actual usage: call getSql() to see the SQL
        $sql = $this->query->getSql();

        // Verify user cares about: getting a valid SQL string
        $this->assertIsString($sql);
        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('users', $sql);
    }

    public function testGetSqlCompilesJoinClauses(): void
    {
        $joinTable = Table::of('profiles', 'default');
        $this->query->leftJoin($joinTable, '[users].[id] = [profiles].[user_id]');

        $this->shardingManager->expects($this->exactly(2))
            ->method('multiple')
            ->willReturnOnConsecutiveCalls(
                ['default' => ['users']],
                ['default' => ['profiles']],
            );

        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->with($this->isArray())
            ->willReturn('SELECT * FROM [users] LEFT JOIN [profiles] ON [users].[id] = [profiles].[user_id]');

        $sql = $this->query->getSql();
        $this->assertStringContainsString('profiles', $sql);
    }

    /**
     * Test that getSql() returns consistent SQL when called multiple times.
     *
     * User perspective: I want to call getSql() multiple times and get the same result.
     * This tests that getSql() is idempotent (calling it multiple times returns the same SQL).
     */
    public function testGetSqlReturnsConsistentResult(): void
    {
        // Setup mocks with explicit expectations
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn(['default' => ['users']]);

        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);
        // Configure other methods that won't be called
        $this->namedLookup->expects($this->never())
            ->method('names');

        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->with($this->isArray())
            ->willReturn('SELECT * FROM [users]');

        // Configure methods that won't be called to avoid notices
        $this->dbClient->expects($this->never())
            ->method('fetchAll');
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

        // User's actual usage: call getSql() multiple times
        $sql1 = $this->query->getSql();
        $sql2 = $this->query->getSql();

        // Verify user cares about: getting the same SQL string when called multiple times
        $this->assertEquals($sql1, $sql2, 'getSql() should return the same SQL when called multiple times');
        $this->assertIsString($sql1);
        $this->assertNotEmpty($sql1);
    }

    /**
     * Test that getSql() throws exception for multiple shards.
     */
    public function testGetSqlThrowsExceptionForMultipleShards(): void
    {
        // Mock sharding manager to return multiple tables
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn(['default' => ['users_0', 'users_1']]);

        // Configure expectations for other mocks that won't be called due to exception
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

        $this->expectException(\Switon\Sharding\Exception\ShardingTooManyException::class);

        $this->query->getSql();
    }

    /**
     * Test execute() with JOIN query.
     */
    public function testExecuteWithJoin(): void
    {
        $expectedResults = [
            ['id' => 1, 'name' => 'John', 'profile_id' => 1],
        ];

        $joinTable = Table::of('profiles', 'default');
        $this->query->leftJoin($joinTable, '[users].[id] = [profiles].[user_id]');

        // Mock sharding manager
        $this->shardingManager->expects($this->exactly(2))
            ->method('multiple')
            ->willReturnOnConsecutiveCalls(
                ['default' => ['users']], // Main table shards
                ['default' => ['profiles']] // Join table shards
            );

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
            ->willReturn('SELECT * FROM [users] LEFT JOIN [profiles] ON [users].[id] = [profiles].[user_id]');

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

        $results = $this->query->execute();

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Test execute() with column mapping.
     */
    public function testExecuteWithColumnMap(): void
    {
        $dbResults = [
            ['user_id' => 1, 'user_name' => 'John'],
        ];

        $expectedResults = [
            ['userId' => 1, 'userName' => 'John'],
        ];

        $this->query->setColumnMap([
            'userId' => 'user_id',
            'userName' => 'user_name',
        ]);

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
            ->willReturn($dbResults);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT [user_id], [user_name] FROM [users]');

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

        $results = $this->query->execute();

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Test execute() with indexBy.
     */
    public function testExecuteWithIndexBy(): void
    {
        $dbResults = [
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ];

        $expectedResults = [
            1 => ['id' => 1, 'name' => 'John'],
            2 => ['id' => 2, 'name' => 'Jane'],
        ];

        $this->query->indexBy('id');

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
            ->willReturn($dbResults);

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

        $results = $this->query->execute();

        $this->assertEquals($expectedResults, $results);
        $this->assertArrayHasKey(1, $results);
        $this->assertArrayHasKey(2, $results);
    }

    /**
     * Test execute() with map function.
     */
    public function testExecuteWithMap(): void
    {
        $dbResults = [
            ['id' => 1, 'name' => 'John'],
        ];

        $this->query->map(function ($row) {
            $row['upper_name'] = strtoupper($row['name']);
            return $row;
        });

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
            ->willReturn($dbResults);

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

        // Map is applied in fetch(), not execute(), so use fetch() instead
        $results = $this->query->fetch();

        $this->assertIsArray($results);
        $this->assertArrayHasKey('upper_name', $results[0]);
        $this->assertEquals('JOHN', $results[0]['upper_name']);
    }

    /**
     * Test execute() with ORDER BY and multiple shards.
     */
    public function testExecuteWithOrderByMultipleShards(): void
    {
        $shard1Results = [
            ['id' => 2, 'name' => 'Jane'],
            ['id' => 4, 'name' => 'Bob'],
        ];

        $shard2Results = [
            ['id' => 1, 'name' => 'John'],
            ['id' => 3, 'name' => 'Alice'],
        ];

        $expectedResults = [
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
            ['id' => 3, 'name' => 'Alice'],
            ['id' => 4, 'name' => 'Bob'],
        ];

        $this->query->orderBy(['id' => SORT_ASC]);

        // Mock sharding manager to return multiple shards
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn([
                'default' => ['users_0', 'users_1'],
            ]);

        // Mock named lookup - should be called twice for two shards
        $this->namedLookup->expects($this->exactly(2))
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client - called twice for two shards
        $this->dbClient->expects($this->exactly(2))
            ->method('fetchAll')
            ->willReturnOnConsecutiveCalls($shard1Results, $shard2Results);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->exactly(2))
            ->method('buildSql')
            ->willReturn('SELECT * FROM [users_0] ORDER BY [id] ASC');

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

        $results = $this->query->execute();

        // Results should be merged and sorted
        $this->assertCount(4, $results);
        $this->assertEquals(1, $results[0]['id']);
        $this->assertEquals(4, $results[3]['id']);
    }

    /**
     * Test execute() with LIMIT and multiple shards.
     */
    public function testExecuteWithLimitMultipleShards(): void
    {
        $shard1Results = [
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ];

        $shard2Results = [
            ['id' => 3, 'name' => 'Alice'],
        ];

        $this->query->limit(2, 0);

        // Mock sharding manager
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn([
                'default' => ['users_0', 'users_1'],
            ]);

        // Mock named lookup - may be called 1 time if first shard has enough results
        // When limit is 2 and first shard returns 2 results, second shard is not queried
        $this->namedLookup->expects($this->atLeast(1))
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client - first shard returns 2 results which meets limit (2), so only called once
        $this->dbClient->expects($this->atLeast(1))
            ->method('fetchAll')
            ->willReturn($shard1Results); // First shard has 2 results, meets limit

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->atLeast(1))
            ->method('buildSql')
            ->willReturn('SELECT * FROM [users_0] LIMIT 2');

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

        $results = $this->query->execute();

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

        // Should return limited results (2 from first shard, which meets the limit)
        $this->assertLessThanOrEqual(2, count($results));
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    /**
     * Test execute() with HAVING clause.
     */
    public function testExecuteWithHaving(): void
    {
        $expectedResults = [
            ['status' => 1, 'count' => 5],
        ];

        $this->query->groupBy('status');
        $this->query->having('COUNT(*) > 3');

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
            ->willReturn('SELECT * FROM [users] GROUP BY [status] HAVING COUNT(*) > 3');

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

        $results = $this->query->execute();

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Test execute() with FOR UPDATE.
     */
    public function testExecuteWithForUpdate(): void
    {
        $expectedResults = [
            ['id' => 1, 'name' => 'John'],
        ];

        $this->query->forUpdate(true);

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
            ->willReturn('SELECT * FROM [users] FOR UPDATE');

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

        $results = $this->query->execute();

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Test count() with GROUP BY.
     */
    public function testCountWithGroupBy(): void
    {
        // Mock sharding manager
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn(['default' => ['users']]);

        // Mock named lookup
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT COUNT(*) as [row_count] FROM [users] GROUP BY [status]');

        // Mock db client - with GROUP BY, count returns number of groups
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['status' => 1],
                ['status' => 2],
                ['status' => 3],
            ]);

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

        $this->query->groupBy('status');
        $count = $this->query->count();

        // With GROUP BY, count returns number of groups (rows in result)
        $this->assertEquals(3, $count);
    }

    /**
     * Test aggregate() with GROUP BY.
     */
    public function testAggregateWithGroupBy(): void
    {
        $expectedResults = [
            ['status' => 1, 'total' => 100],
            ['status' => 2, 'total' => 200],
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
            ->willReturn('SELECT SUM([amount]) AS [total], [status] FROM [users] GROUP BY [status]');

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

        $this->query->groupBy('status');
        $results = $this->query->aggregate([
            'total' => 'SUM(amount)',
        ]);

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Test that column mapping works correctly in execute() results.
     * Tests the user-facing behavior: property names are returned instead of column names.
     */
    public function testColumnMapTransformsResultsCorrectly(): void
    {
        $this->query->setColumnMap([
            'userId' => 'user_id',
            'userName' => 'user_name',
        ]);

        $dbResults = [
            ['user_id' => 1, 'user_name' => 'John'],
        ];

        $expectedResults = [
            ['userId' => 1, 'userName' => 'John'],
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

        // Mock db client - returns column names from database
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn($dbResults);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT [user_id], [user_name] FROM [users]');

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

        $results = $this->query->execute();

        // Verify column names are transformed to property names in results
        $this->assertEquals($expectedResults, $results);
        $this->assertArrayHasKey('userId', $results[0]);
        $this->assertArrayHasKey('userName', $results[0]);
        $this->assertArrayNotHasKey('user_id', $results[0]);
        $this->assertArrayNotHasKey('user_name', $results[0]);
    }

    /**
     * Test __toString() returns SQL string.
     */
    public function testToString(): void
    {
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
        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT * FROM [users]');

        // Configure expectations for methods that won't be called
        $this->dbClient->expects($this->never())
            ->method('fetchAll');
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

        $sql = (string)$this->query;

        $this->assertEquals('SELECT * FROM [users]', $sql);
    }

    /**
     * Test __toString() returns empty string on error.
     */
    public function testToStringReturnsEmptyOnError(): void
    {
        // Mock sharding manager to throw exception
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willThrowException(new Exception('Test error'));

        // Configure expectations for methods that won't be called due to exception
        $this->namedLookup->expects($this->never())
            ->method('by');
        $this->dbClient->expects($this->never())
            ->method('getPrefix');
        $this->dbClient->expects($this->never())
            ->method('buildSql');
        $this->dbClient->expects($this->never())
            ->method('fetchAll');
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

        $sql = (string)$this->query;

        $this->assertEquals('', $sql);
    }

    /**
     * Test values() with multiple shards.
     */
    public function testValuesWithMultipleShards(): void
    {
        $shard1Results = [
            ['name' => 'John'],
            ['name' => 'Jane'],
        ];

        $shard2Results = [
            ['name' => 'Alice'],
            ['name' => 'John'], // Duplicate
        ];

        // Mock sharding manager
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn([
                'default' => ['users_0', 'users_1'],
            ]);

        // Mock named lookup
        $this->namedLookup->expects($this->exactly(2))
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client
        $this->dbClient->expects($this->exactly(2))
            ->method('fetchAll')
            ->willReturnOnConsecutiveCalls($shard1Results, $shard2Results);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->exactly(2))
            ->method('buildSql')
            ->willReturn('SELECT DISTINCT [name] FROM [users_0]');

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

        $values = $this->query->values('name');

        // Should deduplicate values
        $this->assertCount(3, $values);
        $this->assertContains('John', $values);
        $this->assertContains('Jane', $values);
        $this->assertContains('Alice', $values);
    }

    /**
     * Test values() with ORDER BY on multiple shards.
     */
    public function testValuesWithOrderByMultipleShards(): void
    {
        $shard1Results = [
            ['name' => 'Zebra'],
            ['name' => 'Alice'],
        ];

        $shard2Results = [
            ['name' => 'John'],
            ['name' => 'Bob'],
        ];

        $this->query->orderBy(['name' => SORT_ASC]);

        // Mock sharding manager
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn([
                'default' => ['users_0', 'users_1'],
            ]);

        // Mock named lookup
        $this->namedLookup->expects($this->exactly(2))
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client
        $this->dbClient->expects($this->exactly(2))
            ->method('fetchAll')
            ->willReturnOnConsecutiveCalls($shard1Results, $shard2Results);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->exactly(2))
            ->method('buildSql')
            ->willReturn('SELECT DISTINCT [name] FROM [users_0]');

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

        $values = $this->query->values('name');

        // Should be sorted
        $this->assertCount(4, $values);
        // First should be alphabetically first
        $this->assertEquals('Alice', $values[0]);
    }

    /**
     * Test execute() with indexBy callback.
     */
    public function testExecuteWithIndexByCallback(): void
    {
        $dbResults = [
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ];

        $this->query->indexBy(function ($row) {
            return 'user_' . $row['id'];
        });

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
            ->willReturn($dbResults);

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

        $results = $this->query->execute();

        $this->assertArrayHasKey('user_1', $results);
        $this->assertArrayHasKey('user_2', $results);
    }

    /**
     * Test execute() with empty result.
     */
    public function testExecuteWithEmptyResult(): void
    {
        // Mock sharding manager
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn(['default' => ['users']]);

        // Mock named lookup
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client to return empty result
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

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

        $results = $this->query->execute();

        $this->assertEquals([], $results);
    }

    /**
     * Test aggregate() with AVG on multiple shards.
     */
    public function testAggregateAvgWithMultipleShards(): void
    {
        // Mock sharding manager
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn([
                'default' => ['users_0'],
                'read' => ['users_1'],
            ]);

        // Mock named lookup
        $this->namedLookup->expects($this->exactly(2))
            ->method('by')
            ->willReturnCallback(function ($interface, $connection) {
                return $this->dbClient;
            });

        // Mock db client - AVG is converted to SUM and COUNT
        $this->dbClient->expects($this->exactly(2))
            ->method('fetchAll')
            ->willReturnOnConsecutiveCalls(
                [['avg_sum' => 100, 'avg_count' => 4]], // shard 0: sum=100, count=4
                [['avg_sum' => 200, 'avg_count' => 6]]  // shard 1: sum=200, count=6
            );

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->exactly(2))
            ->method('buildSql')
            ->willReturn('SELECT SUM(age) AS [avg_sum], COUNT(*) AS [avg_count] FROM [users_0]');

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

        // Use reflection to test aggregate with AVG
        $results = $this->query->aggregate(['avg' => 'AVG(age)']);

        // AVG should be calculated as (100+200)/(4+6) = 30
        $this->assertArrayHasKey(0, $results);
        $this->assertArrayHasKey('avg', $results[0]);
    }

    /**
     * Test count() with empty result.
     */
    public function testCountWithEmptyResult(): void
    {
        // Mock sharding manager
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn(['default' => ['users']]);

        // Mock named lookup
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client to return empty result
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT COUNT(*) as [row_count] FROM [users]');

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

        $count = $this->query->count();

        $this->assertEquals(0, $count);
    }

    /**
     * Test execute() with table prefix.
     */
    public function testExecuteWithTablePrefix(): void
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

        // Mock db client with prefix
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn($expectedResults);

        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('app_');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT * FROM [app_users]');

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

        $results = $this->query->execute();

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Test execute() with DISTINCT.
     */
    public function testExecuteWithDistinct(): void
    {
        $expectedResults = [
            ['name' => 'John'],
        ];

        $this->query->distinct(true);

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
            ->willReturn('SELECT DISTINCT * FROM [users]');

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

        $results = $this->query->execute();

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Test that exists() does not mutate the original query state.
     *
     * Bug: exists() directly modified fields/limit/offset,
     * permanently altering the query. Now uses clone.
     */
    public function testExistsDoesNotMutateOriginalQuery(): void
    {
        // Arrange - set specific fields and limit on query
        $this->query->select(['id', 'name'])->limit(10, 5);

        $clauses = $this->query->getClauses();
        $originalFields = $clauses->fields;
        $originalLimit = $clauses->limit;
        $originalOffset = $clauses->offset;

        $this->setupMockForSingleShard([['stub' => 1]]);

        // Act
        $exists = $this->query->exists();

        // Assert - exists() should return true
        $this->assertTrue($exists);

        // Assert - original query state should NOT be changed
        $this->assertEquals($originalFields, $clauses->fields, 'exists() should not mutate fields');
        $this->assertEquals($originalLimit, $clauses->limit, 'exists() should not mutate limit');
        $this->assertEquals($originalOffset, $clauses->offset, 'exists() should not mutate offset');
    }

    /**
     * Test that values() does not mutate the original query state.
     *
     * Bug: values() called $this->distinct()->select([$field]) directly,
     * permanently altering the query's distinct and fields settings. Now uses clone.
     */
    public function testValuesDoesNotMutateOriginalQuery(): void
    {
        // Arrange - query starts without distinct, with custom fields
        $this->query->select(['id', 'name', 'email']);

        $clauses = $this->query->getClauses();
        $originalFields = $clauses->fields;
        $originalDistinct = $clauses->distinct;

        $this->setupMockForSingleShard([
            ['name' => 'John'],
            ['name' => 'Jane'],
        ]);

        // Act
        $values = $this->query->values('name');

        // Assert - values should be returned correctly
        $this->assertCount(2, $values);
        $this->assertContains('John', $values);
        $this->assertContains('Jane', $values);

        // Assert - original query state should NOT be changed
        $this->assertEquals($originalFields, $clauses->fields, 'values() should not mutate fields');
        $this->assertEquals($originalDistinct, $clauses->distinct, 'values() should not mutate distinct');
    }
}
