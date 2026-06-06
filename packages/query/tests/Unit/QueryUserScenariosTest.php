<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\ContainerInterface;
use Switon\Db\ClientInterface;
use Switon\Di\NamedLookupInterface;
use Switon\Query\Query;
use Switon\Query\Table;
use Switon\Query\Tests\QueryTestHelper;
use Switon\Query\Tests\TestCase;
use Switon\Sharding\ShardingManagerInterface;

/**
 * Test cases for Query from user perspective.
 *
 * These tests focus on real-world usage scenarios rather than implementation details.
 * Tests verify "what" the query does, not "how" it does it.
 *
 * @group query
 */
#[AllowMockObjectsWithoutExpectations]
class QueryUserScenariosTest extends TestCase
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

        // Create container for dependency injection
        $container = new \Switon\Testing\Container();
        $container->set(\Switon\Core\ContainerInterface::class, $container);
        $container->set(\Psr\Container\ContainerInterface::class, $container);

        // Create mocks and stubs
        // Use stubs when using method() instead of expects() to avoid PHPUnit notices
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->mockContainer = $this->createStub(ContainerInterface::class);
        $this->namedLookup = $this->createStub(NamedLookupInterface::class);
        $this->dbClient = $this->createStub(ClientInterface::class);
        $this->shardingManager = $this->createStub(ShardingManagerInterface::class);

        // Create query instance with mocked dependencies
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
     * Test typical user list query scenario.
     *
     * User perspective: I want to get active users, sorted by name, with pagination.
     * This is a common real-world scenario.
     */
    public function testUserListQueryScenario(): void
    {
        $expectedResults = [
            ['id' => 2, 'name' => 'Alice', 'status' => 'active', 'email' => 'alice@example.com'],
            ['id' => 1, 'name' => 'Bob', 'status' => 'active', 'email' => 'bob@example.com'],
        ];

        $this->setupMockForSingleShard($expectedResults);

        // User's actual usage pattern
        $results = $this->query
            ->where(['status' => 'active'])
            ->orderBy(['name' => 'ASC'])
            ->limit(10, 0)
            ->execute();

        // Verify user cares about: results count and content
        $this->assertCount(2, $results);
        $this->assertEquals('Alice', $results[0]['name']);
        $this->assertEquals('Bob', $results[1]['name']);
        $this->assertEquals('active', $results[0]['status']);
    }

    /**
     * Test search scenario with multiple conditions.
     *
     * User perspective: I want to search users by name and filter by status.
     * This tests complex filtering that users actually use.
     */
    public function testSearchUsersScenario(): void
    {
        $expectedResults = [
            ['id' => 1, 'name' => 'John Doe', 'status' => 'active', 'email' => 'john@example.com'],
        ];

        $this->setupMockForSingleShard($expectedResults);

        // User's actual usage pattern: search with multiple conditions
        $results = $this->query
            ->where(['name*=' => 'John', 'status' => 'active'])
            ->execute();

        $this->assertCount(1, $results);
        $this->assertEquals('John Doe', $results[0]['name']);
        $this->assertEquals('active', $results[0]['status']);
    }

    /**
     * Test pagination scenario.
     *
     * User perspective: I want to get users page by page with total count.
     * This tests the paginate() method which is commonly used.
     */
    public function testPaginationScenario(): void
    {
        // Create 10 items to match page size, so count() will be called
        $page1Results = [];
        for ($i = 1; $i <= 10; $i++) {
            $page1Results[] = ['id' => $i, 'name' => "User $i"];
        }

        $container = $this->createStub(ContainerInterface::class);
        $paginator = new \Switon\Query\Paginator();
        $container->method('make')
            ->willReturn($paginator);

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

        // Setup mocks for paginate() which calls fetch() and count()
        // Note: count() is only called when items count equals page size
        $this->shardingManager->method('multiple')
            ->willReturn(['default' => ['users']]);

        $this->namedLookup->method('by')
            ->willReturn($this->dbClient);

        $this->dbClient->method('getPrefix')
            ->willReturn('');

        // buildSql is called twice: once for fetch(), once for count()
        $this->dbClient->method('buildSql')
            ->willReturnOnConsecutiveCalls(
                'SELECT * FROM [users] LIMIT 10 OFFSET 0', // fetch()
                'SELECT COUNT(*) as [row_count] FROM [users]' // count()
            );

        // fetchAll is called twice: once for fetch(), once for count()
        $this->dbClient->method('fetchAll')
            ->willReturnOnConsecutiveCalls(
                $page1Results, // fetch() call - returns 10 items (equals size)
                [['row_count' => 25]] // count() call - total count
            );

        // Configure methods that won't be called to avoid notices
        $this->dbClient->method('executeInsert')
            ->willReturn(0);
        $this->dbClient->method('executeUpdate')
            ->willReturn(0);
        $this->dbClient->method('executeDelete')
            ->willReturn(0);

        // User's actual usage pattern
        $result = $query->paginate(1, 10);

        // Verify user cares about: page info and items
        $this->assertInstanceOf(\Switon\Query\PaginatorInterface::class, $result);
        $this->assertEquals(1, $result->page);
        $this->assertEquals(10, $result->size);
        $this->assertEquals(25, $result->count); // Total count from database
        $this->assertCount(10, $result->items);
    }

    /**
     * Test filtering with IN clause.
     *
     * User perspective: I want to get users with specific IDs.
     * This is a common filtering pattern.
     */
    public function testFilterByIdsScenario(): void
    {
        $expectedResults = [
            ['id' => 1, 'name' => 'User 1'],
            ['id' => 3, 'name' => 'User 3'],
        ];

        $this->setupMockForSingleShard($expectedResults);

        // User's actual usage pattern: filter by multiple IDs
        $results = $this->query
            ->where(['id' => [1, 3, 5]])
            ->execute();

        $this->assertCount(2, $results);
        $this->assertEquals(1, $results[0]['id']);
        $this->assertEquals(3, $results[1]['id']);
    }

    /**
     * Test aggregate query scenario.
     *
     * User perspective: I want to get statistics about users.
     * This tests aggregation which is commonly used for reports.
     */
    public function testUserStatisticsScenario(): void
    {
        $expectedResults = [
            ['total_users' => 100, 'active_users' => 75, 'total_revenue' => 50000.50],
        ];

        $this->setupMockForSingleShard($expectedResults);
        $this->dbClient->method('buildSql')
            ->willReturn('SELECT COUNT(*) AS [total_users], SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) AS [active_users], SUM(revenue) AS [total_revenue] FROM [users]');

        // User's actual usage pattern: get statistics
        $results = $this->query->aggregate([
            'total_users' => 'COUNT(*)',
            'active_users' => 'SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END)',
            'total_revenue' => 'SUM(revenue)',
        ]);

        // Verify user cares about: statistics values
        $this->assertCount(1, $results);
        $this->assertEquals(100, $results[0]['total_users']);
        $this->assertEquals(75, $results[0]['active_users']);
        $this->assertEquals(50000.50, $results[0]['total_revenue']);
    }

    /**
     * Test complex query with multiple conditions and sorting.
     *
     * User perspective: I want to get users matching complex criteria, sorted and limited.
     * This tests a realistic complex query scenario.
     */
    public function testComplexQueryScenario(): void
    {
        $expectedResults = [
            ['id' => 5, 'name' => 'Charlie', 'age' => 30, 'status' => 'active', 'score' => 95],
            ['id' => 3, 'name' => 'David', 'age' => 28, 'status' => 'active', 'score' => 90],
        ];

        $this->setupMockForSingleShard($expectedResults);

        // User's actual usage pattern: complex query with multiple conditions
        $results = $this->query
            ->where([
                'status' => 'active',
                'age>=' => 25,
                'age<=' => 35,
                'score>' => 85,
            ])
            ->orderBy(['score' => 'DESC', 'name' => 'ASC'])
            ->limit(10, 0)
            ->execute();

        // Verify user cares about: filtered and sorted results
        $this->assertCount(2, $results);
        $this->assertGreaterThanOrEqual(25, $results[0]['age']);
        $this->assertLessThanOrEqual(35, $results[0]['age']);
        $this->assertEquals('active', $results[0]['status']);
        // Results should be sorted by score DESC
        $this->assertGreaterThanOrEqual($results[1]['score'], $results[0]['score']);
    }

    /**
     * Test query with range filtering.
     *
     * User perspective: I want to get users within a numeric range.
     * This tests range filtering which is commonly used.
     */
    public function testRangeFilterScenario(): void
    {
        $expectedResults = [
            ['id' => 1, 'name' => 'User 1', 'age' => 25],
            ['id' => 2, 'name' => 'User 2', 'age' => 30],
        ];

        $this->setupMockForSingleShard($expectedResults);

        // User's actual usage pattern: filter by range using ~= operator
        $results = $this->query
            ->where(['age~=' => [20, 35]])
            ->execute();

        $this->assertCount(2, $results);
        // Verify all results are within the range
        foreach ($results as $result) {
            $this->assertGreaterThanOrEqual(20, $result['age']);
            $this->assertLessThanOrEqual(35, $result['age']);
        }
    }

    /**
     * Test query that returns no results.
     *
     * User perspective: I want to handle the case when no users match my criteria.
     * This tests edge case that users need to handle.
     */
    public function testNoResultsScenario(): void
    {
        $this->setupMockForSingleShard([]);

        // User's actual usage pattern: query that returns no results
        $results = $this->query
            ->where(['status' => 'deleted', 'id' => 999])
            ->execute();

        // Verify user cares about: empty result handling
        $this->assertIsArray($results);
        $this->assertCount(0, $results);
        $this->assertEmpty($results);
    }

    /**
     * Test first() method usage.
     *
     * User perspective: I want to get the first matching user or null if none.
     * This tests a common pattern for getting a single record.
     */
    public function testGetFirstUserScenario(): void
    {
        $expectedResult = ['id' => 1, 'name' => 'First User', 'status' => 'active'];

        $this->setupMockForSingleShard([$expectedResult]);

        // User's actual usage pattern: get first matching record
        $result = $this->query
            ->where(['status' => 'active'])
            ->orderBy(['id' => 'ASC'])
            ->first();

        // Verify user cares about: getting the first record or null
        $this->assertIsArray($result);
        $this->assertEquals(1, $result['id']);
        $this->assertEquals('First User', $result['name']);
    }

    /**
     * Test get() method throws exception when not found.
     *
     * User perspective: I want to ensure a user exists, or get an exception.
     * This tests error handling that users need to handle.
     */
    public function testGetUserThrowsExceptionWhenNotFound(): void
    {
        $this->setupMockForSingleShard([]);

        // User's actual usage pattern: expect a user to exist
        $this->expectException(\Switon\Query\Exception\NotFoundException::class);

        $this->query
            ->where(['id' => 999])
            ->get();
    }

    /**
     * Test count() method usage.
     *
     * User perspective: I want to know how many users match my criteria.
     * This tests counting which is commonly used.
     */
    public function testCountUsersScenario(): void
    {
        // Setup mocks for count() which needs buildSql and fetchAll
        $this->shardingManager->method('multiple')
            ->willReturn(['default' => ['users']]);

        $this->namedLookup->method('by')
            ->willReturn($this->dbClient);

        $this->dbClient->method('getPrefix')
            ->willReturn('');

        $this->dbClient->method('buildSql')
            ->willReturn('SELECT COUNT(*) as [row_count] FROM [users]');

        $this->dbClient->method('fetchAll')
            ->willReturn([['row_count' => 42]]);

        // Configure methods that won't be called to avoid notices
        $this->dbClient->method('executeInsert')
            ->willReturn(0);
        $this->dbClient->method('executeUpdate')
            ->willReturn(0);
        $this->dbClient->method('executeDelete')
            ->willReturn(0);

        // User's actual usage pattern: count matching records
        $count = $this->query
            ->where(['status' => 'active'])
            ->count();

        // Verify user cares about: the count number
        $this->assertEquals(42, $count);
    }
}
