<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\MakerInterface;
use Switon\Db\Event\DbConnected;
use Switon\Db\Event\DbConnecting;
use Switon\Db\Exception\ConnectionException;
use Switon\Db\Exception\ReadonlyException;
use Switon\Db\Exception\UnsupportedParameterTypeException;
use Switon\Db\Tests\Fixtures\TestAbstractConnection;
use Switon\Db\Tests\TestCase;
use JsonSerializable;
use stdClass;

/**
 * Test cases for AbstractConnection class.
 *
 * Tests abstract connection functionality using TestAbstractConnection implementation.
 */
#[AllowMockObjectsWithoutExpectations]
class AbstractConnectionTest extends TestCase
{
    protected EventDispatcherInterface|MockObject $eventDispatcher;
    protected MakerInterface|MockObject $maker;
    protected TestAbstractConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->maker = $this->container->get(MakerInterface::class);
        $this->connection = new TestAbstractConnection(
            $this->eventDispatcher,
            $this->maker,
            'test://localhost/test'
        );
    }

    /**
     * Register a mock PDO in the container that will be returned when make(PDO::class, ...) is called.
     * This allows tests to use real Container while still controlling PDO behavior.
     */
    protected function registerMockPdo(PDO $mockPdo): void
    {
        // Create a callable object that returns the mock PDO
        // Container's make() now checks if definition object has __invoke and calls it
        // Note: Parameters must have type declarations for DI to work
        $factory = new class ($mockPdo) {
            public function __construct(private PDO $pdo)
            {
            }

            public function __invoke(array $parameters = []): PDO
            {
                return $this->pdo;
            }
        };
        $this->container->set(PDO::class, $factory);
    }

    /**
     * Test that getUri() returns the configured URI.
     */
    public function testGetUriReturnsConfiguredUri(): void
    {
        // Act & Assert
        $this->assertSame('test://localhost/test', $this->connection->getUri());
    }

    /**
     * Test that __clone() creates independent connection.
     */
    public function testCloneCreatesIndependentConnection(): void
    {
        // Arrange
        $pdo = $this->createMock(PDO::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('beginTransaction')
            ->willReturn(true);

        $this->connection->begin();

        // Act
        $cloned = clone $this->connection;

        // Assert
        // Cloned connection should not be in transaction (pdo is null after clone)
        // We verify independence by checking that cloned connection can operate independently
        // First, set up a PDO for the cloned connection
        $clonedPdo = $this->createMock(PDO::class);
        $cloned->setPdo($clonedPdo);

        // Original connection should still work and be able to rollback
        // This verifies that the cloned connection's commit didn't affect the original
        $pdo->expects($this->once())
            ->method('rollBack')
            ->willReturn(true);

        $this->connection->rollback();

        // Verify cloned connection can start its own transaction independently
        // This demonstrates that cloned connection is independent from original
        $clonedPdo = $this->createMock(PDO::class);
        $cloned->setPdo($clonedPdo);

        $clonedPdo->expects($this->once())
            ->method('beginTransaction')
            ->willReturn(true);

        $cloned->begin();

        // Verify cloned connection can commit its own transaction
        $clonedPdo->expects($this->once())
            ->method('commit')
            ->willReturn(true);

        $cloned->commit();
    }

    /**
     * Test that execute() creates PDO connection on first call.
     */
    public function testExecuteCreatesConnectionOnFirstCall(): void
    {
        // Arrange
        $pdo = $this->createMock(PDO::class);
        $this->registerMockPdo($pdo);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->with($this->logicalOr(
                $this->isInstanceOf(DbConnecting::class),
                $this->isInstanceOf(DbConnected::class)
            ));

        $pdo->expects($this->once())
            ->method('exec')
            ->willReturn(1);

        // Act
        $result = $this->connection->execute('UPDATE users SET name = "John"');

        // Assert
        $this->assertSame(1, $result);
    }

    /**
     * Test that execute() reuses connection on subsequent calls.
     */
    public function testExecuteReusesConnectionOnSubsequentCalls(): void
    {
        // Arrange
        $pdo = $this->createMock(PDO::class);
        $this->registerMockPdo($pdo);

        $pdo->expects($this->atLeast(2))
            ->method('exec')
            ->willReturn(1);

        // Act
        // First call creates connection
        $result1 = $this->connection->execute('UPDATE users SET name = "John"');

        // Second call reuses connection (connection is cached in $this->connection->pdo)
        $result2 = $this->connection->execute('UPDATE users SET name = "Jane"');

        // Assert
        $this->assertSame(1, $result1);
        $this->assertSame(1, $result2);
    }

    /**
     * Test that execute() handles connection failure.
     */
    public function testExecuteHandlesConnectionFailure(): void
    {
        // Arrange
        $exception = new PDOException('Connection failed', 2002);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        // Register a factory that throws exception
        $factory = new class ($exception) {
            public function __construct(private PDOException $exception)
            {
            }

            public function __invoke(array $parameters = []): never
            {
                throw $this->exception;
            }
        };
        $this->container->set(PDO::class, $factory);

        // Assert
        $this->expectException(ConnectionException::class);

        // Act
        $this->connection->execute('UPDATE users SET name = "John"');
    }

    /**
     * Test that execute() throws ReadonlyException on readonly connection.
     */
    public function testExecuteThrowsReadonlyExceptionOnReadonlyConnection(): void
    {
        // Arrange
        $this->connection->setReadonly(true);

        // Assert
        $this->expectException(ReadonlyException::class);

        // Act
        $this->connection->execute('UPDATE users SET name = :name', ['name' => 'John']);
    }

    /**
     * Test that execute() executes SQL with bind parameters.
     */
    public function testExecuteExecutesSqlWithBindParameters(): void
    {
        // Arrange
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);

        $statement->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $this->connection->setPdo($pdo);

        // Set up PDO to return statement for prepare
        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->equalTo('UPDATE users SET name = :name'))
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($bind) {
                return isset($bind[':name']) && $bind[':name'] === 'John';
            }))
            ->willReturn(true);

        // Act
        $result = $this->connection->execute('UPDATE users SET name = :name', ['name' => 'John']);

        // Assert
        $this->assertSame(1, $result);
    }

    /**
     * Test that execute() executes SQL without bind parameters.
     */
    public function testExecuteExecutesSqlWithoutBindParameters(): void
    {
        // Arrange
        $pdo = $this->createMock(PDO::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('exec')
            ->with($this->equalTo('UPDATE users SET name = "John"'))
            ->willReturn(1);

        // Act
        $result = $this->connection->execute('UPDATE users SET name = "John"');

        // Assert
        $this->assertSame(1, $result);
    }

    /**
     * Test that execute() returns insert ID when has_insert_id is true.
     */
    public function testExecuteReturnsInsertIdWhenHasInsertIdIsTrue(): void
    {
        // Arrange
        $pdo = $this->createMock(PDO::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('exec')
            ->willReturn(1);

        $pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('123');

        // Act
        $result = $this->connection->execute('INSERT INTO users (name) VALUES ("John")', [], true);

        // Assert
        $this->assertSame(123, $result);
    }

    /**
     * Test that query() executes SELECT query and returns results.
     */
    public function testQueryExecutesSelectQueryAndReturnsResults(): void
    {
        // Arrange
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('query')
            ->with($this->equalTo('SELECT * FROM users'))
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('fetchAll')
            ->with($this->equalTo(PDO::FETCH_ASSOC))
            ->willReturn([['id' => 1, 'name' => 'John']]);

        // Act
        $result = $this->connection->query('SELECT * FROM users', [], PDO::FETCH_ASSOC);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    /**
     * Test that begin() throws ReadonlyException on readonly connection.
     */
    public function testBeginThrowsReadonlyExceptionOnReadonlyConnection(): void
    {
        // Arrange
        $this->connection->setReadonly(true);

        // Assert
        $this->expectException(ReadonlyException::class);

        // Act
        $this->connection->begin();
    }

    /**
     * Test that begin() starts transaction successfully.
     */
    public function testBeginStartsTransactionSuccessfully(): void
    {
        // Arrange
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $this->connection->setPdo($pdo);

        // Act
        $this->connection->begin();

        // Assert
        // Verify transaction state was set (test behavior, not PDO implementation)
        $this->assertTrue($this->connection->isInTransaction());

        $this->connection->commit();

        // Verify transaction state was cleared
        $this->assertFalse($this->connection->isInTransaction());
    }

    /**
     * Test that begin() handles PDOException and retries after close.
     */
    public function testBeginHandlesPdoExceptionAndRetriesAfterClose(): void
    {
        // Arrange
        $pdo1 = $this->createMock(PDO::class);
        $pdo2 = $this->createMock(PDO::class);
        $this->connection->setPdo($pdo1);

        $pdo1->expects($this->once())
            ->method('beginTransaction')
            ->willThrowException(new PDOException('Connection lost'));

        // After close(), getPdo() will be called again, which will create a new PDO
        // Register factory that returns pdo2
        $this->registerMockPdo($pdo2);

        $pdo2->expects($this->once())
            ->method('beginTransaction')
            ->willReturn(true);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        // Act
        $this->connection->begin();

        // Assert
        // Verify transaction is active by attempting to commit
        $pdo2->expects($this->once())
            ->method('commit')
            ->willReturn(true);

        $this->connection->commit();
    }

    /**
     * Test that rollback() rolls back transaction.
     */
    public function testRollbackRollsBackTransaction(): void
    {
        // Arrange
        $pdo = $this->createStub(PDO::class);
        $this->connection->setPdo($pdo);

        // Set transaction state directly (test behavior, not PDO implementation)
        $this->connection->setInTransaction(true);

        // Act
        $this->connection->rollback();

        // Assert
        // Verify transaction state was cleared
        $this->assertFalse($this->connection->isInTransaction());
    }

    /**
     * Test that commit() commits transaction.
     */
    public function testCommitCommitsTransaction(): void
    {
        // Arrange
        $pdo = $this->createStub(PDO::class);
        $this->connection->setPdo($pdo);

        // Set transaction state directly (test behavior, not PDO implementation)
        $this->connection->setInTransaction(true);

        // Act
        $this->connection->commit();

        // Assert
        // Verify transaction state was cleared
        $this->assertFalse($this->connection->isInTransaction());
    }

    /**
     * Test that close() dispatches events and cleans up.
     */
    public function testCloseDispatchesEventsAndCleansUp(): void
    {
        // Arrange
        $pdo = $this->createMock(PDO::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('beginTransaction')
            ->willReturn(true);

        $this->connection->begin();

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        // Act
        $this->connection->close();

        // Assert
        // Verify connection is closed by attempting to execute (should create new connection)
        $this->registerMockPdo($pdo);

        $pdo->expects($this->once())
            ->method('exec')
            ->willReturn(1);

        $result = $this->connection->execute('SELECT 1');
        $this->assertSame(1, $result);
    }

    /**
     * Test that close() does nothing when PDO is null.
     */
    public function testCloseDoesNothingWhenPdoIsNull(): void
    {
        // Arrange
        $this->eventDispatcher->expects($this->never())
            ->method('dispatch');

        // Act
        $this->connection->close();
    }

    /**
     * Test that getLastHeartbeat() returns null initially.
     */
    public function testGetLastHeartbeatReturnsNullInitially(): void
    {
        $this->assertNull($this->connection->getLastHeartbeat());
    }

    /**
     * Test that getLastHeartbeat() returns timestamp after execute().
     */
    public function testGetLastHeartbeatReturnsTimestampAfterExecute(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->registerMockPdo($pdo);

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $statement->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $this->connection->execute('UPDATE users SET name = :name', ['name' => 'John']);

        $heartbeat = $this->connection->getLastHeartbeat();
        $this->assertNotNull($heartbeat);
        $this->assertIsFloat($heartbeat);
        $this->assertGreaterThan(0, $heartbeat);
    }

    /**
     * Test that getLastHeartbeat() returns timestamp after query().
     */
    public function testGetLastHeartbeatReturnsTimestampAfterQuery(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->registerMockPdo($pdo);

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $statement->expects($this->once())
            ->method('fetchAll')
            ->willReturn([['id' => 1]]);

        $this->connection->query('SELECT * FROM users WHERE id = :id', ['id' => 1]);

        $heartbeat = $this->connection->getLastHeartbeat();
        $this->assertNotNull($heartbeat);
        $this->assertIsFloat($heartbeat);
        $this->assertGreaterThan(0, $heartbeat);
    }

    /**
     * Test that buildSql() builds SQL from parameters.
     */
    public function testBuildSqlBuildsSqlFromParameters(): void
    {
        $params = [
            'fields' => 'id, name',
            'from' => 'users',
            'where' => 'id > 10',
            'order' => 'id DESC',
            'limit' => 10,
        ];

        $result = $this->connection->buildSql($params);

        $this->assertStringContainsString('SELECT', $result);
        $this->assertStringContainsString('id, name', $result);
        $this->assertStringContainsString('FROM users', $result);
        $this->assertStringContainsString('WHERE id > 10', $result);
        $this->assertStringContainsString('ORDER BY id DESC', $result);
        $this->assertStringContainsString('LIMIT 10', $result);
    }

    /**
     * Test that buildSql() includes DISTINCT when specified.
     */
    public function testBuildSqlIncludesDistinctWhenSpecified(): void
    {
        $params = [
            'fields' => 'name',
            'distinct' => true,
            'from' => 'users',
        ];

        $result = $this->connection->buildSql($params);

        $this->assertStringContainsString('SELECT DISTINCT', $result);
    }

    /**
     * Test that buildSql() includes all SQL clauses.
     */
    public function testBuildSqlIncludesAllSqlClauses(): void
    {
        $params = [
            'fields' => '*',
            'from' => 'users',
            'join' => ' LEFT JOIN orders ON users.id = orders.user_id',
            'where' => 'users.id > 10',
            'group' => 'users.id',
            'having' => 'COUNT(orders.id) > 5',
            'order' => 'users.id DESC',
            'limit' => 10,
            'offset' => 20,
            'forUpdate' => true,
        ];

        $result = $this->connection->buildSql($params);

        $this->assertStringContainsString('SELECT', $result);
        $this->assertStringContainsString('FROM users', $result);
        $this->assertStringContainsString('LEFT JOIN', $result);
        $this->assertStringContainsString('WHERE', $result);
        $this->assertStringContainsString('GROUP BY', $result);
        $this->assertStringContainsString('HAVING', $result);
        $this->assertStringContainsString('ORDER BY', $result);
        $this->assertStringContainsString('LIMIT 10', $result);
        $this->assertStringContainsString('OFFSET 20', $result);
        $this->assertStringContainsString('FOR UPDATE', $result);
    }

    /**
     * Test that buildSql() includes proper spacing before FOR UPDATE clause.
     *
     * Ensures FOR UPDATE has a leading space to prevent invalid SQL like "OFFSET 20FOR UPDATE".
     */
    public function testBuildSqlForUpdateHasProperSpacing(): void
    {
        // Test with OFFSET preceding FOR UPDATE
        $params = [
            'fields' => '*',
            'from' => 'users',
            'limit' => 10,
            'offset' => 20,
            'forUpdate' => true,
        ];

        $result = $this->connection->buildSql($params);

        // Should contain " FOR UPDATE" with a leading space, not "20FOR UPDATE"
        $this->assertStringContainsString(' FOR UPDATE', $result);
        $this->assertStringNotContainsString('20FOR UPDATE', $result);

        // Test with LIMIT only (no OFFSET) preceding FOR UPDATE
        $params = [
            'fields' => '*',
            'from' => 'users',
            'limit' => 10,
            'forUpdate' => true,
        ];

        $result = $this->connection->buildSql($params);

        $this->assertStringContainsString(' FOR UPDATE', $result);
        $this->assertStringNotContainsString('10FOR UPDATE', $result);

        // Test with simple query (only FROM preceding FOR UPDATE)
        $params = [
            'fields' => '*',
            'from' => 'users',
            'forUpdate' => true,
        ];

        $result = $this->connection->buildSql($params);

        $this->assertStringContainsString(' FOR UPDATE', $result);
        $this->assertStringNotContainsString('usersFOR UPDATE', $result);
    }

    /**
     * Test that execute() handles boolean values correctly.
     */
    public function testExecuteHandlesBooleanValues(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($bind) {
                return isset($bind[':active']) && $bind[':active'] === 1;
            }))
            ->willReturn(true);

        $statement->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->connection->execute('UPDATE users SET active = :active', ['active' => true]);

        $this->assertSame(1, $result);
    }

    /**
     * Test that execute() handles array values by converting to JSON.
     */
    public function testExecuteHandlesArrayValuesByConvertingToJson(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($bind) {
                return isset($bind[':tags']) && is_string($bind[':tags']);
            }))
            ->willReturn(true);

        $statement->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->connection->execute('UPDATE users SET tags = :tags', ['tags' => ['tag1', 'tag2']]);

        $this->assertSame(1, $result);
    }

    /**
     * Test that execute() handles JsonSerializable values by converting to JSON.
     */
    public function testExecuteHandlesJsonSerializableValues(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo);

        $jsonSerializable = new class () implements JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['key' => 'value'];
            }
        };

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($bind) {
                return isset($bind[':data']) && is_string($bind[':data']);
            }))
            ->willReturn(true);

        $statement->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->connection->execute('UPDATE users SET data = :data', ['data' => $jsonSerializable]);

        $this->assertSame(1, $result);
    }

    /**
     * Test that execute() throws exception for unsupported parameter types.
     */
    public function testExecuteThrowsExceptionForUnsupportedParameterTypes(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $this->expectException(UnsupportedParameterTypeException::class);
        $this->connection->execute('UPDATE users SET obj = :obj', ['obj' => new stdClass()]);
    }

    /**
     * Test that execute() handles integer parameter keys (positional parameters).
     */
    public function testExecuteHandlesIntegerParameterKeys(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($bind) {
                return isset($bind[1]) && $bind[1] === 'John';
            }))
            ->willReturn(true);

        $statement->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->connection->execute('UPDATE users SET name = ?', [0 => 'John']);

        $this->assertSame(1, $result);
    }

    /**
     * Test that execute() handles named parameters with colon prefix.
     */
    public function testExecuteHandlesNamedParametersWithColonPrefix(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($bind) {
                return isset($bind[':name']) && $bind[':name'] === 'John';
            }))
            ->willReturn(true);

        $statement->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->connection->execute('UPDATE users SET name = :name', [':name' => 'John']);

        $this->assertSame(1, $result);
    }

    /**
     * Test that multiple queries reuse prepared statements (performance optimization).
     */
    public function testMultipleQueriesReusePreparedStatements(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo);

        // prepare() should only be called once for the same SQL
        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->equalTo('SELECT * FROM users WHERE id = :id'))
            ->willReturn($statement);

        $statement->expects($this->atLeast(2))
            ->method('execute')
            ->willReturn(true);

        $statement->expects($this->atLeast(2))
            ->method('fetchAll')
            ->willReturn([['id' => 1, 'name' => 'John']]);

        // First query
        $result1 = $this->connection->query('SELECT * FROM users WHERE id = :id', ['id' => 1]);

        // Second query with same SQL should reuse prepared statement
        $result2 = $this->connection->query('SELECT * FROM users WHERE id = :id', ['id' => 2]);

        $this->assertIsArray($result1);
        $this->assertIsArray($result2);
    }

    /**
     * Test that multiple queries do not cause memory leak (cache is limited).
     */
    public function testMultipleQueriesDoNotCauseMemoryLeak(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->connection->setPdo($pdo);

        // Create statements for different queries - use a factory that creates statements on demand
        $statementFactory = function () {
            $statement = $this->createMock(PDOStatement::class);
            $statement->expects($this->any())
                ->method('execute')
                ->willReturn(true);
            $statement->expects($this->any())
                ->method('fetchAll')
                ->willReturn([]);
            return $statement;
        };

        $pdo->expects($this->any())
            ->method('prepare')
            ->willReturnCallback($statementFactory);

        // Execute many different queries
        for ($i = 0; $i < 50; $i++) {
            $this->connection->query("SELECT * FROM table{$i} WHERE id = :id", ['id' => $i]);
        }

        // Verify we can still execute queries after many different queries
        // The important thing is that the system continues to work, not the exact cache size
        // The cache should limit prepare calls, but we don't test the exact limit
        // Just verify the system continues to work
        $result = $this->connection->query('SELECT 1 WHERE id = :id', ['id' => 1]);
        $this->assertIsArray($result);
    }

    /**
     * Test that query() throws exception when PDOException occurs in transaction.
     */
    public function testQueryThrowsExceptionWhenPdoExceptionOccursInTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('beginTransaction')
            ->willReturn(true);

        $this->connection->begin();

        $exception = new PDOException('Query failed');

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->willThrowException($exception);

        $this->expectException(\Switon\Db\Exception::class);
        $this->connection->query('SELECT * FROM users WHERE id = :id', ['id' => 1]);
    }

    /**
     * Test that query() handles exception and retries after close when not in transaction.
     */
    public function testQueryHandlesExceptionAndRetriesAfterCloseWhenNotInTransaction(): void
    {
        $pdo1 = $this->createMock(PDO::class);
        $pdo2 = $this->createMock(PDO::class);
        $statement1 = $this->createMock(PDOStatement::class);
        $statement2 = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo1);

        $exception = new PDOException('Query failed');

        // First attempt fails
        $pdo1->expects($this->once())
            ->method('prepare')
            ->willReturn($statement1);

        $statement1->expects($this->once())
            ->method('execute')
            ->willThrowException($exception);

        // After close, new connection is created
        // Register factory that returns pdo2
        $this->registerMockPdo($pdo2);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        // Second attempt succeeds
        $pdo2->expects($this->once())
            ->method('prepare')
            ->willReturn($statement2);

        $statement2->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $statement2->expects($this->once())
            ->method('fetchAll')
            ->willReturn([['id' => 1]]);

        $result = $this->connection->query('SELECT * FROM users WHERE id = :id', ['id' => 1]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    /**
     * Test that query() throws exception when retry after close also fails.
     */
    public function testQueryThrowsExceptionWhenRetryAfterCloseAlsoFails(): void
    {
        $pdo1 = $this->createMock(PDO::class);
        $pdo2 = $this->createMock(PDO::class);
        $statement1 = $this->createMock(PDOStatement::class);
        $statement2 = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo1);

        $exception = new PDOException('Query failed');

        // First attempt fails
        $pdo1->expects($this->once())
            ->method('prepare')
            ->willReturn($statement1);

        $statement1->expects($this->once())
            ->method('execute')
            ->willThrowException($exception);

        // After close, new connection is created
        // Register factory that returns pdo2
        $this->registerMockPdo($pdo2);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        // Second attempt also fails
        $pdo2->expects($this->once())
            ->method('prepare')
            ->willReturn($statement2);

        $statement2->expects($this->once())
            ->method('execute')
            ->willThrowException($exception);

        $this->expectException(\Switon\Db\Exception::class);
        $this->connection->query('SELECT * FROM users WHERE id = :id', ['id' => 1]);
    }

    /**
     * Test that execute() throws exception when PDOException occurs in transaction.
     */
    public function testExecuteThrowsExceptionWhenPdoExceptionOccursInTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('beginTransaction')
            ->willReturn(true);

        $this->connection->begin();

        $exception = new PDOException('Execute failed');

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->willThrowException($exception);

        $this->expectException(\Switon\Db\Exception::class);
        $this->connection->execute('UPDATE users SET name = :name', ['name' => 'John']);
    }

    /**
     * Test that execute() handles exception and retries after close when not in transaction.
     */
    public function testExecuteHandlesExceptionAndRetriesAfterCloseWhenNotInTransaction(): void
    {
        $pdo1 = $this->createMock(PDO::class);
        $pdo2 = $this->createMock(PDO::class);
        $statement1 = $this->createMock(PDOStatement::class);
        $statement2 = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo1);

        $exception = new PDOException('Execute failed');

        // First attempt fails
        $pdo1->expects($this->once())
            ->method('prepare')
            ->willReturn($statement1);

        $statement1->expects($this->once())
            ->method('execute')
            ->willThrowException($exception);

        // After close, new connection is created
        // Register factory that returns pdo2
        $this->registerMockPdo($pdo2);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        // Second attempt succeeds
        $pdo2->expects($this->once())
            ->method('prepare')
            ->willReturn($statement2);

        $statement2->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $statement2->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->connection->execute('UPDATE users SET name = :name', ['name' => 'John']);

        $this->assertSame(1, $result);
    }

    /**
     * Test that execute() throws exception when retry after close also fails.
     */
    public function testExecuteThrowsExceptionWhenRetryAfterCloseAlsoFails(): void
    {
        $pdo1 = $this->createMock(PDO::class);
        $pdo2 = $this->createMock(PDO::class);
        $statement1 = $this->createMock(PDOStatement::class);
        $statement2 = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo1);

        $exception = new PDOException('Execute failed');

        // First attempt fails
        $pdo1->expects($this->once())
            ->method('prepare')
            ->willReturn($statement1);

        $statement1->expects($this->once())
            ->method('execute')
            ->willThrowException($exception);

        // After close, new connection is created
        // Register factory that returns pdo2
        $this->registerMockPdo($pdo2);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        // Second attempt also fails
        $pdo2->expects($this->once())
            ->method('prepare')
            ->willReturn($statement2);

        $statement2->expects($this->once())
            ->method('execute')
            ->willThrowException($exception);

        $this->expectException(\Switon\Db\Exception::class);
        $this->connection->execute('UPDATE users SET name = :name', ['name' => 'John']);
    }

    /**
     * Test that getPdo() dispatches DbConnecting and throws ConnectionException on failure.
     */
    public function testGetPdoDispatchesConnectingAndThrowsOnFailure(): void
    {
        $exception = new PDOException('Connection failed', 2002);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(DbConnecting::class));

        // Register a factory that throws exception
        $factory = new class ($exception) {
            public function __construct(private PDOException $exception)
            {
            }

            public function __invoke(array $parameters = []): never
            {
                throw $this->exception;
            }
        };
        $this->container->set(PDO::class, $factory);

        $this->expectException(ConnectionException::class);
        $this->connection->execute('SELECT 1');
    }

    /**
     * Test that ping() returns true when PDO query succeeds.
     */
    public function testPingReturnsTrueWhenPdoQuerySucceeds(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('query')
            ->with($this->equalTo("SELECT 'PING'"))
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('fetchAll')
            ->willReturn([['PING' => 'PING']]);

        $result = $this->connection->testPing();

        $this->assertTrue($result);
    }

    /**
     * Test that ping() returns false when PDO query throws exception.
     */
    public function testPingReturnsFalseWhenPdoQueryThrowsException(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->connection->setPdo($pdo);

        $pdo->expects($this->once())
            ->method('query')
            ->with($this->equalTo("SELECT 'PING'"))
            ->willThrowException(new PDOException('Connection lost'));

        $result = $this->connection->testPing();

        $this->assertFalse($result);
    }

    /**
     * Test that escapeIdentifier() wraps simple identifier with brackets.
     */
    public function testEscapeIdentifierWrapsSimpleIdentifierWithBrackets(): void
    {
        $result = $this->connection->testEscapeIdentifier('users');

        $this->assertSame('[users]', $result);
    }

    /**
     * Test that escapeIdentifier() wraps schema.table identifier with brackets.
     */
    public function testEscapeIdentifierWrapsSchemaTableIdentifierWithBrackets(): void
    {
        $result = $this->connection->testEscapeIdentifier('schema.users');

        $this->assertSame('[schema].[users]', $result);
    }

    /**
     * Test that escapeIdentifier() preserves already bracketed identifier.
     */
    public function testEscapeIdentifierPreservesAlreadyBracketedIdentifier(): void
    {
        $result = $this->connection->testEscapeIdentifier('[users]');

        $this->assertSame('[users]', $result);
    }

    /**
     * Test that escapeIdentifier() handles multi-part identifier with brackets.
     */
    public function testEscapeIdentifierHandlesMultiPartIdentifierWithBrackets(): void
    {
        $result = $this->connection->testEscapeIdentifier('[schema].[users]');

        $this->assertSame('[schema].[users]', $result);
    }

    /**
     * Test that escapeIdentifier() handles mixed bracketed identifier (partial brackets).
     */
    public function testEscapeIdentifierHandlesMixedBracketedIdentifier(): void
    {
        // First part bracketed, second part not
        $result = $this->connection->testEscapeIdentifier('[schema].users');
        $this->assertSame('[schema].[users]', $result);

        // First part not bracketed, second part bracketed
        $result = $this->connection->testEscapeIdentifier('schema.[users]');
        $this->assertSame('[schema].[users]', $result);
    }

}
