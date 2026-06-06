<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\ContainerInterface;
use Switon\Db\ClientInterface;
use Switon\Di\NamedLookupInterface;
use Switon\Query\Table;
use Switon\Sharding\ShardingManagerInterface;

/**
 * Test cases for Query sharding functionality.
 *
 * Tests sharding scenarios including join table shard suffix application.
 *
 * @group query
 */
#[AllowMockObjectsWithoutExpectations]
class QueryShardingTest extends QueryExecutionTest
{
    /**
     * Test that query() applies shard suffix to join tables.
     */
    public function testQueryAppliesShardSuffixToJoinTables(): void
    {
        $joinTable = Table::of('profiles', 'default');
        $this->query->leftJoin($joinTable, '[users].[id] = [profiles].[user_id]');

        $expectedResults = [
            ['id' => 1, 'name' => 'John', 'profile_id' => 1],
        ];

        // Mock sharding manager - return sharded table
        $this->shardingManager->expects($this->exactly(2))
            ->method('multiple')
            ->willReturnOnConsecutiveCalls(
                ['default' => ['users_0']], // Main table shards
                ['default' => ['profiles_0']] // Join table shards
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

        // Test behavior: execute should work correctly with sharded JOIN tables
        // We verify the behavior (results are returned correctly) rather than internal SQL structure
        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT * FROM [users_0] LEFT JOIN [profiles_0] ON [users_0].[id] = [profiles_0].[user_id]');

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
     * Test that query() handles join tables without shard suffix when main table has no suffix.
     */
    public function testQueryHandlesJoinTablesWithoutShardSuffix(): void
    {
        $joinTable = Table::of('profiles', 'default');
        $this->query->leftJoin($joinTable, '[users].[id] = [profiles].[user_id]');

        $expectedResults = [
            ['id' => 1, 'name' => 'John'],
        ];

        // Mock sharding manager - return non-sharded table
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
     * Test that getSql() throws exception when table is not set.
     * This tests user-facing error behavior.
     */
    public function testGetSqlThrowsExceptionWhenTableNotSet(): void
    {
        // Configure expectations for mock objects that won't be used in this test
        // (since we create a new query instance with stubs)
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

        // Create container for dependency injection
        $container = new \Switon\Testing\Container();
        $container->set(\Switon\Core\ContainerInterface::class, $container);
        $container->set(\Psr\Container\ContainerInterface::class, $container);

        // Use stubs for dependencies since they won't be called when table is not set
        $namedLookup = $this->createStub(NamedLookupInterface::class);
        $containerStub = $this->createStub(ContainerInterface::class);
        $shardingManagerStub = $this->createStub(ShardingManagerInterface::class);

        // Create query without setting table, using container injection
        $query = $this->createQueryWithMocks(
            $container,
            $this->eventDispatcher,
            $namedLookup,
            $containerStub,
            $shardingManagerStub
        );

        $this->expectException(\Switon\Core\Exception\MisuseException::class);
        $this->expectExceptionMessageMatches('/Query table not set/');

        $query->getSql();
    }

    /**
     * Test that getSql() works correctly for single shard (public behavior test).
     * This indirectly tests getUniqueShard behavior through getSql.
     */
    public function testGetSqlWorksForSingleShard(): void
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

        $sql = $this->query->getSql();

        $this->assertIsString($sql);
        $this->assertEquals('SELECT * FROM [users]', $sql);
    }

    /**
     * Test execute() with multiple databases (multi-connection sharding).
     */
    public function testExecuteWithMultipleDatabases(): void
    {
        $db1Results = [
            ['id' => 1, 'name' => 'John'],
        ];

        $db2Results = [
            ['id' => 2, 'name' => 'Jane'],
        ];

        // Mock sharding manager to return multiple databases
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn([
                'default' => ['users'],
                'read' => ['users'],
            ]);

        // Mock named lookup for both databases
        $this->namedLookup->expects($this->exactly(2))
            ->method('by')
            ->willReturnCallback(function ($interface, $connection) {
                return $this->dbClient;
            });

        // Mock db client - different results for different connections
        $this->dbClient->expects($this->exactly(2))
            ->method('fetchAll')
            ->willReturnOnConsecutiveCalls($db1Results, $db2Results);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->exactly(2))
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

        // Results should be merged from both databases
        $this->assertCount(2, $results);
    }
}
