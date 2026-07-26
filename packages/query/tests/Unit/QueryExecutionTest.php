<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\App;
use Switon\Core\ContainerInterface;
use Switon\Db\ClientInterface;
use Switon\Di\NamedLookupInterface;
use Switon\Query\Event\QuerySkipped;
use Switon\Query\Query;
use Switon\Query\Table;
use Switon\Query\Tests\QueryTestHelper;
use Switon\Query\Tests\TestCase;
use Switon\Sharding\ShardingManagerInterface;

/**
 * Test cases for Query execution methods.
 *
 * Tests query execution, aggregation, and data modification methods using mocks.
 */
#[AllowMockObjectsWithoutExpectations]
class QueryExecutionTest extends TestCase
{
    use QueryTestHelper;

    protected Query $query;
    protected MockObject|EventDispatcherInterface $eventDispatcher;
    protected MockObject|NamedLookupInterface $namedLookup;
    protected MockObject|ClientInterface $dbClient;
    protected MockObject|ContainerInterface $mockContainer;
    protected MockObject|ShardingManagerInterface $shardingManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Create container for dependency injection using testing container
        $container = new \Switon\Testing\Container();
        $container->set(\Switon\Core\ContainerInterface::class, $container);
        $container->set(\Psr\Container\ContainerInterface::class, $container);

        // Create mocks and stubs
        // Use stubs for dependencies that don't need expectations in all tests
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->mockContainer = $this->createStub(ContainerInterface::class);
        // Use mocks (not stubs) when using method() instead of expects() to avoid brittle expectations
        // Individual tests can still use expects() when they need to verify specific interactions
        $this->namedLookup = $this->createMock(NamedLookupInterface::class);
        $this->dbClient = $this->createMock(ClientInterface::class);
        $this->shardingManager = $this->createMock(ShardingManagerInterface::class);

        // Create query instance with mocked dependencies using container injection
        $this->query = $this->createQueryWithMocks(
            $container,
            $this->eventDispatcher,
            $this->namedLookup,
            $this->mockContainer,
            $this->shardingManager
        );

        // Setup default table
        $this->query->setTable(Table::of('users', 'default'));
    }

    /**
     * Setup mock for single shard scenario.
     * Helper method to reduce duplication - focuses on behavior, not implementation.
     * Uses method() instead of expects() to avoid brittle expectations.
     */
    protected function setupMockForSingleShard(array $results = []): void
    {
        $this->shardingManager->method('multiple')
            ->willReturn(['default' => ['users']]);

        $this->namedLookup->method('by')
            ->willReturn($this->dbClient);

        $this->dbClient->method('getPrefix')
            ->willReturn('');

        $this->dbClient->method('buildSql')
            ->willReturn('SELECT * FROM [users]');

        if (!empty($results)) {
            $this->dbClient->method('fetchAll')
                ->willReturn($results);
        } else {
            $this->dbClient->method('fetchAll')
                ->willReturn([]);
        }

        // Configure methods that won't be called in query scenarios to avoid notices
        $this->dbClient->method('executeInsert')
            ->willReturn(0);
        $this->dbClient->method('executeUpdate')
            ->willReturn(0);
        $this->dbClient->method('executeDelete')
            ->willReturn(0);
        $this->dbClient->method('delete')
            ->willReturn(0);
        $this->dbClient->method('update')
            ->willReturn(0);
    }

    /**
     * Test that execute() returns query results.
     */
    public function testExecute(): void
    {
        $expectedResults = [
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ];

        // Mock sharding manager to return single shard
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn(['default' => ['users']]);

        // Mock named lookup to return db client
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client fetchAll
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

        $results = $this->query->execute();

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Test that execute() returns empty array when condition is FALSE.
     */
    public function testExecuteWithFalseCondition(): void
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

        // Set a condition that results in FALSE
        $this->query->whereIn('id', []);

        // Create mock event dispatcher for this test to verify event dispatch
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $querySkippedFound = false;
        $query = null;
        $eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->with($this->anything())
            ->willReturnCallback(function ($event) use (&$querySkippedFound, &$query) {
                if ($event instanceof QuerySkipped) {
                    $querySkippedFound = true;
                    $this->assertSame($query, $event->getSender());
                }
                return $event;
            });

        // Recreate query with mock event dispatcher
        // Use stubs for other dependencies since they won't be called when condition is FALSE
        $container = new \Switon\Testing\Container();
        $container->set(\Switon\Core\ContainerInterface::class, $container);
        $container->set(\Psr\Container\ContainerInterface::class, $container);
        $namedLookup = $this->createStub(NamedLookupInterface::class);
        $containerStub = $this->createStub(ContainerInterface::class);
        $shardingManagerStub = $this->createStub(ShardingManagerInterface::class);
        $query = $this->createQueryWithMocks(
            $container,
            $eventDispatcher,
            $namedLookup,
            $containerStub,
            $shardingManagerStub
        );
        $query->setTable(Table::of('users', 'default'));
        $query->whereIn('id', []);

        $results = $query->execute();

        $this->assertEquals([], $results);
        $this->assertTrue($querySkippedFound, 'QuerySkipped event should be dispatched');
    }

    /**
     * Test that count() returns count of records.
     */
    public function testCount(): void
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
            ->willReturn('SELECT COUNT(*) as [row_count] FROM [users]');

        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->with($this->isString(), $this->isArray())
            ->willReturn([['row_count' => 42]]);

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

        $this->assertEquals(42, $count);
    }

    /**
     * Test that exists() returns true when records exist.
     */
    public function testExistsReturnsTrue(): void
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

        // Mock db client to return a result
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn([['stub' => 1]]);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT 1 as [stub] FROM [users] LIMIT 1');

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

        $exists = $this->query->exists();

        $this->assertTrue($exists);
    }

    /**
     * Test that exists() returns false when no records exist.
     */
    public function testExistsReturnsFalse(): void
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
            ->willReturn('SELECT 1 as [stub] FROM [users] LIMIT 1');

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

        $exists = $this->query->exists();

        $this->assertFalse($exists);
    }

    /**
     * Test that first() returns first record or null.
     */
    public function testFirstReturnsFirstRecord(): void
    {
        $expectedRecord = ['id' => 1, 'name' => 'John'];

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
            ->willReturn([$expectedRecord]);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT * FROM [users] LIMIT 1');

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

        $result = $this->query->first();

        $this->assertEquals($expectedRecord, $result);
    }

    /**
     * Test that first() returns null when no records.
     */
    public function testFirstReturnsNull(): void
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
            ->willReturn('SELECT * FROM [users] LIMIT 1');

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

        $result = $this->query->first();

        $this->assertNull($result);
    }

    /**
     * Test that get() returns single record.
     */
    public function testGetReturnsRecord(): void
    {
        $expectedRecord = ['id' => 1, 'name' => 'John'];

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
            ->willReturn([$expectedRecord]);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT * FROM [users] LIMIT 1');

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

        $result = $this->query->get();

        $this->assertEquals($expectedRecord, $result);
    }

    /**
     * Test that get() throws NotFoundException when no record found.
     */
    public function testGetThrowsNotFoundException(): void
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
            ->willReturn('SELECT * FROM [users] LIMIT 1');

        // Configure expectations for methods that won't be called
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

        $this->expectException(\Switon\Query\Exception\NotFoundException::class);

        $this->query->get();
    }

    /**
     * Test that all() returns all records.
     */
    public function testAll(): void
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

        $results = $this->query->all();

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Test that values() returns array of field values.
     */
    public function testValues(): void
    {
        $expectedValues = ['John', 'Jane', 'Bob'];

        // Mock sharding manager
        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->willReturn(['default' => ['users']]);

        // Mock named lookup
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client to return rows with name field
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['name' => 'John'],
                ['name' => 'Jane'],
                ['name' => 'Bob'],
            ]);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT DISTINCT [name] FROM [users]');

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

        $this->assertEquals($expectedValues, $values);
    }

    /**
     * Test that delete() returns affected rows count.
     */
    public function testDelete(): void
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

        // Mock db client delete method
        $this->dbClient->expects($this->once())
            ->method('delete')
            ->with(
                $this->equalTo('users'),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(5);

        // Configure methods that won't be called to avoid notices
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

        $affected = $this->query->where(['status' => 0])->delete();

        $this->assertEquals(5, $affected);
    }

    /**
     * Test that update() returns affected rows count.
     */
    public function testUpdate(): void
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

        // Mock db client update method
        $this->dbClient->expects($this->once())
            ->method('update')
            ->with(
                $this->equalTo('users'),
                $this->equalTo(['status' => 1]),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(3);

        // Configure methods that won't be called to avoid notices
        $this->dbClient->expects($this->never())
            ->method('fetchAll');
        $this->dbClient->expects($this->never())
            ->method('buildSql');
        $this->dbClient->expects($this->never())
            ->method('getPrefix');
        $this->dbClient->expects($this->never())
            ->method('executeInsert');
        $this->dbClient->expects($this->never())
            ->method('executeDelete');
        $this->dbClient->expects($this->never())
            ->method('delete');

        $affected = $this->query->where(['id' => 1])->update(['status' => 1]);

        $this->assertEquals(3, $affected);
    }

    /**
     * Test that aggregate() executes aggregate query.
     */
    public function testAggregate(): void
    {
        $expectedResults = [
            ['total' => 150.50, 'count' => 10],
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
            ->willReturn('SELECT SUM([amount]) AS [total], COUNT(*) AS [count] FROM [users]');

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

        $results = $this->query->aggregate([
            'total' => 'SUM(amount)',
            'count' => 'COUNT(*)',
        ]);

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Test that sum() returns sum value.
     */
    public function testSum(): void
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

        // Mock db client - sum() calls aggregate() which calls execute()
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn([['r' => 150.50]]);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT SUM(amount) AS [r] FROM [users]');

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

        $sum = $this->query->sum('amount');

        $this->assertEquals(150.50, $sum);
    }

    /**
     * Test that max() returns max value.
     */
    public function testMax(): void
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
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn([['r' => 100]]);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT MAX(age) AS [r] FROM [users]');

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

        $max = $this->query->max('age');

        $this->assertEquals(100, $max);
    }

    /**
     * Test that min() returns min value.
     */
    public function testMin(): void
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
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn([['r' => 18]]);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT MIN(age) AS [r] FROM [users]');

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

        $min = $this->query->min('age');

        $this->assertEquals(18, $min);
    }

    /**
     * Test that avg() returns average value.
     */
    public function testAvg(): void
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
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn([['r' => 25.5]]);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT AVG(age) AS [r] FROM [users]');

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

        $avg = $this->query->avg('age');

        $this->assertEquals(25.5, $avg);
        $this->assertIsFloat($avg);
    }

    /**
     * Test that value() returns field value from first record.
     */
    public function testValue(): void
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
        $this->dbClient->expects($this->once())
            ->method('fetchAll')
            ->willReturn([['name' => 'John']]);

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->once())
            ->method('buildSql')
            ->willReturn('SELECT [name] FROM [users] LIMIT 1');

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

        $value = $this->query->value('name');

        $this->assertEquals('John', $value);
    }

    /**
     * Test that value() returns default when no record found.
     */
    public function testValueReturnsDefault(): void
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
            ->willReturn('SELECT [name] FROM [users] LIMIT 1');

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

        $value = $this->query->value('name', 'DefaultName');

        $this->assertEquals('DefaultName', $value);
    }

    /**
     * Test that fetch() returns results based on fetch type.
     */
    public function testFetchWithMultipleTrue(): void
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

        $this->query->setFetchType(true);
        $results = $this->query->fetch();

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Test that fetch() returns single record when multiple is false.
     */
    public function testFetchWithMultipleFalse(): void
    {
        $expectedRecord = ['id' => 1, 'name' => 'John'];

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
            ->willReturn([$expectedRecord]);

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

        $this->query->setFetchType(false);
        $result = $this->query->fetch();

        $this->assertEquals($expectedRecord, $result);
    }

    /**
     * Test that paginate() returns Paginator with results.
     */
    public function testPaginate(): void
    {
        // Create 10 items to match page size, so count() will be called
        $expectedItems = [];
        for ($i = 1; $i <= 10; $i++) {
            $expectedItems[] = ['id' => $i, 'name' => "User $i"];
        }

        // Use real container with factory for paginator to align with runtime behavior
        $container = new \Switon\Testing\Container();
        $container->set(\Switon\Core\ContainerInterface::class, $container);
        $container->set(\Psr\Container\ContainerInterface::class, $container);
        $container->set(\Switon\Query\PaginatorInterface::class, new class () {
            public function __invoke(): \Switon\Query\Paginator
            {
                return new \Switon\Query\Paginator();
            }
        });
        // Set global container for make() function
        App::setContainer($container);

        // Recreate query with mock container
        $diContainer = new \Switon\Testing\Container();
        $diContainer->set(\Switon\Core\ContainerInterface::class, $diContainer);
        $diContainer->set(\Psr\Container\ContainerInterface::class, $diContainer);
        $query = $this->createQueryWithMocks(
            $diContainer,
            $this->eventDispatcher,
            $this->namedLookup,
            $container,
            $this->shardingManager
        );
        $query->setTable(Table::of('users', 'default'));

        // Mock sharding manager for fetch() and count() calls
        $this->shardingManager->expects($this->exactly(2))
            ->method('multiple')
            ->willReturn(['default' => ['users']]);

        // Mock named lookup for fetch() and count()
        $this->namedLookup->expects($this->exactly(2))
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($this->dbClient);

        // Mock db client for fetch() and count() calls
        $this->dbClient->expects($this->exactly(2))
            ->method('fetchAll')
            ->willReturnOnConsecutiveCalls(
                $expectedItems, // fetch() call - returns 10 items (equals size)
                [['row_count' => 25]] // count() call - total count
            );

        // getPrefix may be called multiple times during buildSql (for main table and join tables)
        $this->dbClient->expects($this->atLeastOnce())
            ->method('getPrefix')
            ->willReturn('');

        $this->dbClient->expects($this->exactly(2))
            ->method('buildSql')
            ->willReturnOnConsecutiveCalls(
                'SELECT * FROM [users] LIMIT 10 OFFSET 0', // fetch()
                'SELECT COUNT(*) as [row_count] FROM [users]' // count()
            );

        $result = $query->paginate(1, 10);

        $this->assertInstanceOf(\Switon\Query\PaginatorInterface::class, $result);
        $this->assertEquals(1, $result->page);
        $this->assertEquals(10, $result->size);
        $this->assertEquals(25, $result->count); // Total count from database
        $this->assertEquals($expectedItems, $result->items);

        // Restore container to the default test container to avoid side effects
        App::setContainer($this->container);
    }
}
