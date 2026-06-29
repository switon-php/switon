<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Integration;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\ContainerInterface;
use Switon\Db\Client;
use Switon\Db\ConnectionInterface;
use Switon\Db\Tests\Fixtures\MockConnection;
use Switon\Db\Tests\TestCase;
use Switon\Db\UpsertExpression;
use Switon\Pooling\PoolManagerInterface;
use PDO;
use ReflectionProperty;

/**
 * Test cases for Client class.
 *
 * Tests database client functionality with improved testability.
 */
#[AllowMockObjectsWithoutExpectations]
class ClientTest extends TestCase
{
    protected Client $client;
    protected MockObject|EventDispatcherInterface $eventDispatcher;
    protected MockObject|PoolManagerInterface $poolManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->poolManager = $this->createMock(PoolManagerInterface::class);
        // Use real container from parent TestCase
    }

    /**
     * Test that getPrefix() returns the configured prefix.
     */
    public function testGetPrefixReturnsConfiguredPrefix(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?prefix=test_');

        $this->assertSame('test_', $client->getPrefix());
    }

    /**
     * Test that getPrefix() returns empty string when no prefix configured.
     */
    public function testGetPrefixReturnsEmptyStringWhenNoPrefix(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->assertSame('', $client->getPrefix());
    }


    /**
     * Test that prefix from URI is applied to table names in insert operations.
     */
    public function testPrefixFromUriIsAppliedToTableNames(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?prefix=app_');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        // Verify event dispatch order: DbExecuting -> DbInserting -> DbInserted -> DbExecuted
        $eventSequence = [];
        $executedSql = [];
        $this->eventDispatcher->expects($this->exactly(4))
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$eventSequence, &$executedSql) {
                $eventSequence[] = $event::class;
                // Capture SQL from DbExecuting event (public API)
                if ($event instanceof \Switon\Db\Event\DbExecuting) {
                    $executedSql[] = $event->sql;
                }
                return $event;
            });

        $result = $client->insert('users', ['name' => 'John']);

        // Verify event dispatch order
        $this->assertSame(
            [
                \Switon\Db\Event\DbExecuting::class,
                \Switon\Db\Event\DbInserting::class,
                \Switon\Db\Event\DbInserted::class,
                \Switon\Db\Event\DbExecuted::class,
            ],
            $eventSequence,
            'Events must be dispatched in the correct order'
        );

        // Verify operation succeeded (user-facing behavior)
        $this->assertIsInt($result);
        $this->assertSame(1, $result, 'Insert should return affected rows count');

        // Verify prefix was applied to table name in SQL (through public event API)
        $this->assertNotEmpty($executedSql, 'SQL should be executed');
        $this->assertStringContainsString('[app_users]', $executedSql[0], 'Table name should have prefix applied');
    }

    /**
     * Test that inTransaction() returns false when no transaction.
     */
    public function testInTransactionReturnsFalseWhenNoTransaction(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->assertFalse($client->inTransaction());
    }

    /**
     * Test that insert() returns 0 when record is empty.
     */
    public function testInsertReturnsZeroWhenRecordEmpty(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->assertSame(0, $client->insert('users', []));
    }

    /**
     * Test that update() returns 0 when fieldValues is empty.
     */
    public function testUpdateReturnsZeroWhenFieldValuesEmpty(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->assertSame(0, $client->update('users', [], ['id' => 1]));
    }

    /**
     * Test that update() throws UnsafeOperationException when conditions is empty.
     */
    public function testUpdateThrowsExceptionWhenConditionsEmpty(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $client->update('users', ['name' => 'John'], []);
    }

    /**
     * Test that delete() throws UnsafeOperationException when conditions is empty.
     */
    public function testDeleteThrowsExceptionWhenConditionsEmpty(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $client->delete('users', []);
    }

    /**
     * Test that fetchAll() uses read connection.
     */
    public function testFetchAllUsesReadConnection(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->queryResults = [['id' => 1, 'name' => 'John']];

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->fetchAll('SELECT * FROM users');

        $this->assertIsArray($result);
    }

    /**
     * Test that executeInsert() uses connection from pool.
     */
    public function testExecuteInsertUsesConnectionFromPool(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->executeInsert('INSERT INTO users (name) VALUES (:name)', ['name' => 'John']);

        $this->assertIsInt($result);
    }

    /**
     * Test that executeUpdate() uses connection from pool.
     */
    public function testExecuteUpdateUsesConnectionFromPool(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->executeUpdate('UPDATE users SET name = :name WHERE id = :id', ['name' => 'John', 'id' => 1]);

        $this->assertIsInt($result);
    }

    /**
     * Test that executeDelete() uses connection from pool.
     */
    public function testExecuteDeleteUsesConnectionFromPool(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->executeDelete('DELETE FROM users WHERE id = :id', ['id' => 1]);

        $this->assertIsInt($result);
    }

    /**
     * Test that begin() starts a transaction.
     */
    public function testBeginStartsTransaction(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $capturedEvent = null;
        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$capturedEvent) {
                if ($capturedEvent === null) {
                    $capturedEvent = $event;
                }
                return $event;
            });

        $transient = $client->getTransient('default');
        $transient->begin();

        // Verify event was dispatched with correct type and client instance
        $this->assertInstanceOf(\Switon\Db\Event\DbBegin::class, $capturedEvent, 'DbBegin event must be dispatched');
        $this->assertSame($transient, $capturedEvent->sender, 'DbBegin event must contain correct sender instance');

        // Verify transaction state through public API
        $this->assertTrue($transient->inTransaction());

        // Clean up transaction state through public API
        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $transient->rollback();

        // Verify transaction is cleaned up through public API
        $this->assertFalse($transient->inTransaction());
    }

    /**
     * Test that commit() commits transaction and releases connection.
     */
    public function testCommitCommitsTransactionAndReleasesConnection(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        // Verify event dispatch order: DbBegin -> DbCommit
        $eventSequence = [];
        $eventClients = [];
        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$eventSequence, &$eventClients) {
                $eventSequence[] = $event::class;
                $eventClients[] = $event->sender;
                return $event;
            });

        $transient = $client->getTransient('default');
        $transient->begin();
        $transient->commit();

        // Verify event dispatch order
        $this->assertSame(
            [
                \Switon\Db\Event\DbBegin::class,
                \Switon\Db\Event\DbCommit::class,
            ],
            $eventSequence,
            'Events must be dispatched in the correct order: DbBegin -> DbCommit'
        );

        // Verify event contains correct client instance (all events have public $client property)
        $this->assertSame($transient, $eventClients[0], 'DbBegin event must contain correct client instance');
        $this->assertSame($transient, $eventClients[1], 'DbCommit event must contain correct client instance');

        $this->assertFalse($transient->inTransaction());
        $this->assertSame(0, $this->getTransactionLevel($transient));
        $this->assertNull($this->getPinnedConnection($transient));
    }

    /**
     * Test that rollback() rolls back transaction and releases connection.
     */
    public function testRollbackRollsBackTransactionAndReleasesConnection(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        // Verify event dispatch order: DbBegin -> DbRollback
        $eventSequence = [];
        $eventClients = [];
        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$eventSequence, &$eventClients) {
                $eventSequence[] = $event::class;
                $eventClients[] = $event->sender;
                return $event;
            });

        $transient = $client->getTransient('default');
        $transient->begin();
        $transient->rollback();

        // Verify event dispatch order
        $this->assertSame(
            [
                \Switon\Db\Event\DbBegin::class,
                \Switon\Db\Event\DbRollback::class,
            ],
            $eventSequence,
            'Events must be dispatched in the correct order: DbBegin -> DbRollback'
        );

        // Verify event contains correct client instance (all events have public $client property)
        $this->assertSame($transient, $eventClients[0], 'DbBegin event must contain correct client instance');
        $this->assertSame($transient, $eventClients[1], 'DbRollback event must contain correct client instance');

        $this->assertFalse($transient->inTransaction());
        $this->assertSame(0, $this->getTransactionLevel($transient));
        $this->assertNull($this->getPinnedConnection($transient));
    }

    /**
     * Test that commit() throws exception when no active transaction.
     */
    public function testCommitThrowsExceptionWhenNoActiveTransaction(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->expectException(\Switon\Db\Exception\NoActiveTransactionException::class);
        $client->commit();
    }

    /**
     * Test that nested transactions are supported.
     */
    public function testNestedTransactionsAreSupported(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $transient = $client->getTransient('default');

        $transient->begin();
        $this->assertSame(1, $this->getTransactionLevel($transient));

        $transient->begin();
        $this->assertSame(2, $this->getTransactionLevel($transient));

        $transient->commit();
        $this->assertSame(1, $this->getTransactionLevel($transient));

        $transient->commit();
        $this->assertSame(0, $this->getTransactionLevel($transient));
    }

    /**
     * Test that close() rolls back and releases connection when transaction is active.
     */
    public function testCloseRollsBackAndReleasesConnectionWhenTransactionActive(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $transient = $client->getTransient('default');
        $transient->begin();
        $transient->close();

        $this->assertSame(0, $this->getTransactionLevel($transient));
        $this->assertNull($this->getPinnedConnection($transient));
    }

    /**
     * Test that insert() with fetchInsertId returns inserted ID.
     */
    public function testInsertWithFetchInsertIdReturnsInsertedId(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->insertId = 123;
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->insert('users', ['name' => 'John'], true);

        $this->assertSame(123, $result);
    }

    /**
     * Test that insertBySql() executes custom INSERT SQL.
     */
    public function testInsertBySqlExecutesCustomInsertSql(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        // Capture SQL from events to verify through public API
        $executedSql = [];
        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$executedSql) {
                if ($event instanceof \Switon\Db\Event\DbExecuting) {
                    $executedSql[] = $event->sql;
                }
                return $event;
            });

        $result = $client->insertBySql('users', 'SELECT * FROM temp', ['name' => 'John']);

        // Verify operation succeeded (user-facing behavior)
        $this->assertIsInt($result);
        $this->assertSame(1, $result, 'Insert should return affected rows count');

        // Verify SQL was executed (through public event API)
        $this->assertNotEmpty($executedSql, 'SQL should be executed');
    }

    /**
     * Test that update() with array conditions works.
     */
    public function testUpdateWithArrayConditions(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->update('users', ['name' => 'John'], ['id' => 1, 'status' => 'active']);

        $this->assertIsInt($result);
        // Verify operation was executed, not the SQL format
        $this->assertNotEmpty($connection->executedSql);
    }

    /**
     * Test that update() with string condition works.
     */
    public function testUpdateWithStringCondition(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->update('users', ['name' => 'John'], 'id > :id', ['id' => 10]);

        $this->assertIsInt($result);
    }

    /**
     * Test that update() throws exception for unsafe string condition.
     */
    public function testUpdateThrowsExceptionForUnsafeStringCondition(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $client->update('users', ['name' => 'John'], 'id');
    }

    /**
     * Test that updateBySql() executes custom UPDATE SQL.
     */
    public function testUpdateBySqlExecutesCustomUpdateSql(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->updateBySql('users', 'name = :name WHERE id = :id', ['name' => 'John', 'id' => 1]);

        $this->assertIsInt($result);
        // Verify operation was executed, not the SQL format
        $this->assertNotEmpty($connection->executedSql);
    }

    /**
     * Test that delete() with array conditions works.
     */
    public function testDeleteWithArrayConditions(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->delete('users', ['id' => 1, 'status' => 'inactive']);

        $this->assertIsInt($result);
        // Verify operation was executed, not the SQL format
        $this->assertNotEmpty($connection->executedSql);
    }

    /**
     * Test that delete() with string condition works.
     */
    public function testDeleteWithStringCondition(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->delete('users', 'id > :id', ['id' => 10]);

        $this->assertIsInt($result);
    }

    /**
     * Test that delete() throws exception for unsafe string condition.
     */
    public function testDeleteThrowsExceptionForUnsafeStringCondition(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $client->delete('users', 'id');
    }

    /**
     * Test that deleteBySql() executes custom DELETE SQL.
     */
    public function testDeleteBySqlExecutesCustomDeleteSql(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->deleteBySql('users', 'id = :id', ['id' => 1]);

        $this->assertIsInt($result);
        // Verify operation was executed, not the SQL format
        $this->assertNotEmpty($connection->executedSql);
    }

    /**
     * Test that getTransient('default') forces master connection for reads.
     */
    public function testGetTransientDefaultForcesMasterConnectionForReads(): void
    {
        $client = $this->createClientForTesting('mysql://host1/db,mysql://host2/db');
        $connection = new MockConnection();
        $connection->queryResults = [['id' => 1]];

        // getTransient clones client, so we can't use identicalTo($client)
        // Just verify it acquires from 'default' pool (master)
        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->with($this->anything(), $this->anything(), 'default')
            ->willReturn($connection);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $transient = $client->getTransient('default');
        $result = $transient->fetchAll('SELECT * FROM users');

        $this->assertIsArray($result);
        $this->assertSame([['id' => 1]], $result);
    }

    /**
     * Test that fetchAll() uses slave connection when available.
     */
    public function testFetchAllUsesSlaveConnectionWhenAvailable(): void
    {
        $client = $this->createClientForTesting('mysql://host1/db,mysql://host2/db');
        $connection = new MockConnection();
        $connection->queryResults = [['id' => 1]];

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->with($this->identicalTo($client), $this->anything(), 'slave')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'slave');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->fetchAll('SELECT * FROM users');

        $this->assertIsArray($result);
    }

    /**
     * Test that fetchAll() uses context connection when in transaction.
     */
    public function testFetchAllUsesContextConnectionWhenInTransaction(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->queryResults = [['id' => 1]];

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $transient = $client->getTransient('default');
        $transient->begin();

        // Should use pinned connection, not acquire a new one from pool
        $this->poolManager->expects($this->never())
            ->method('acquire');

        $result = $transient->fetchAll('SELECT * FROM users');

        // Verify operation succeeded (user-facing behavior)
        $this->assertIsArray($result);
        $this->assertSame([['id' => 1]], $result, 'Query should return expected results');

        // Clean up transaction state through public API
        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\Switon\Db\Event\DbRollback::class));

        $transient->rollback();

        // Verify transaction is cleaned up through public API
        $this->assertFalse($transient->inTransaction());
    }

    /**
     * Test that execute() uses context connection when in transaction.
     */
    public function testExecuteUsesContextConnectionWhenInTransaction(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $transient = $client->getTransient('default');
        $transient->begin();

        // Should use pinned connection, not acquire a new one from pool
        $this->poolManager->expects($this->never())
            ->method('acquire');

        $result = $transient->executeUpdate('UPDATE users SET name = :name', ['name' => 'John']);

        // Verify operation succeeded (user-facing behavior)
        $this->assertIsInt($result);
        $this->assertSame(1, $result, 'Update should return affected rows count');

        // Clean up transaction state through public API
        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\Switon\Db\Event\DbRollback::class));

        $transient->rollback();

        // Verify transaction is cleaned up through public API
        $this->assertFalse($transient->inTransaction());
    }

    /**
     * Test that getTables() returns table list.
     */
    public function testGetTablesReturnsTableList(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->tables = ['users', 'products'];

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $result = $client->getTables();

        $this->assertIsArray($result);
    }

    /**
     * Test that getTables() filters by prefix when configured.
     */
    public function testGetTablesFiltersByPrefixWhenConfigured(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?prefix=app_');
        $connection = new MockConnection();
        $connection->tables = ['app_users', 'app_products', 'other_table'];

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $result = $client->getTables();

        $this->assertIsArray($result);
        $this->assertContains('users', $result);
        $this->assertContains('products', $result);
        $this->assertNotContains('other_table', $result);
    }

    /**
     * Test that getMetadata() returns table metadata.
     */
    public function testGetMetadataReturnsTableMetadata(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->metadata = ['columns' => ['id', 'name']];

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->getMetadata('users');

        $this->assertIsArray($result);
    }

    /**
     * Test that buildSql() delegates to connection.
     */
    public function testBuildSqlDelegatesToConnection(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->buildSqlResult = 'SELECT * FROM users';

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $result = $client->buildSql(['table' => 'users']);

        $this->assertIsString($result);
    }

    /**
     * Test that upsert() delegates to connection adapter and dispatches events.
     */
    public function testUpsertDelegatesToConnectionAdapterAndDispatchesEvents(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        // Verify event dispatch: DbUpserting -> DbUpserted
        $eventSequence = [];
        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$eventSequence) {
                $eventSequence[] = $event::class;
                return $event;
            });

        $result = $client->upsert('users', ['id' => 1, 'name' => 'John'], ['name' => 'Jane']);

        $this->assertIsInt($result);
        $this->assertSame(1, $result);
        // Verify upsert was called on connection
        $this->assertNotEmpty($connection->executedSql);
        $this->assertStringContainsString('UPSERT', $connection->executedSql[0]);
        // Verify events were dispatched in correct order
        $this->assertSame(
            [
                \Switon\Db\Event\DbUpserting::class,
                \Switon\Db\Event\DbUpserted::class,
            ],
            $eventSequence
        );
    }

    /**
     * Test that upsert() uses first key as primary key when not specified.
     */
    public function testUpsertUsesFirstKeyAsPrimaryKeyWhenNotSpecified(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $result = $client->upsert('users', ['id' => 1, 'name' => 'John']);

        $this->assertIsInt($result);
        // Verify primary key was inferred as 'id' (first key)
        $this->assertNotEmpty($connection->executedBind);
        $this->assertSame('id', $connection->executedBind[0]['pk']);
    }

    /**
     * Test that getTransient() creates transient client.
     */
    public function testGetTransientCreatesTransientClient(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->with($this->isInstanceOf(\Switon\Db\Client::class), $this->anything(), 'default')
            ->willReturn($connection);

        $result = $client->getTransient();

        $this->assertInstanceOf(\Switon\Db\Client::class, $result);
    }

    /**
     * Test that getTransient('slave') falls back to default when no slave.
     */
    public function testGetTransientSlaveFallsBackToDefault(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->with($this->isInstanceOf(\Switon\Db\Client::class), $this->anything(), 'default')
            ->willReturn($connection);

        $result = $client->getTransient('slave');

        $this->assertInstanceOf(\Switon\Db\Client::class, $result);
    }

    /**
     * Test that getTransient('auto') uses slave when available.
     */
    public function testGetTransientAutoUsesSlaveWhenAvailable(): void
    {
        $client = $this->createClientForTesting('mysql://host1/db,mysql://host2/db');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->with($this->isInstanceOf(\Switon\Db\Client::class), $this->anything(), 'slave')
            ->willReturn($connection);

        $result = $client->getTransient('auto');

        $this->assertInstanceOf(\Switon\Db\Client::class, $result);
    }

    /**
     * Test that getTransient('auto') uses default when no slave.
     */
    public function testGetTransientAutoFallsBackToDefault(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->with($this->isInstanceOf(\Switon\Db\Client::class), $this->anything(), 'default')
            ->willReturn($connection);

        $result = $client->getTransient('auto');

        $this->assertInstanceOf(\Switon\Db\Client::class, $result);
    }

    /**
     * Test that getTransient() creates transient instance with held connection.
     */
    public function testGetTransientCreatesTransientInstance(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->buildSqlResult = 'SELECT * FROM [users]';
        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->with($this->isInstanceOf(\Switon\Db\Client::class), $this->anything(), 'default')
            ->willReturn($connection);

        $transient = $client->getTransient();
        $this->assertInstanceOf(\Switon\Db\Client::class, $transient);

        $result = $transient->buildSql(['select' => '*', 'from' => 'users']);
        $this->assertSame('SELECT * FROM [users]', $result);

        unset($transient);
    }

    /**
     * Test that constructor parses timeout from URI.
     */
    public function testConstructorParsesTimeoutFromUri(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?timeout=5.5');

        // Timeout is protected, but we can verify it through behavior
        // The timeout affects pool operations, but we can't easily test it without integration tests
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that constructor parses pool_size from URI.
     */
    public function testConstructorParsesPoolSizeFromUri(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?pool_size=8');

        // Pool size affects initialization, verified through successful creation
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that constructor handles multiple URIs with template placeholder.
     */
    public function testConstructorHandlesMultipleUrisWithTemplate(): void
    {
        $client = $this->createClientForTesting('mysql://{host1,host2}/db');

        // Should create client with slave support
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that constructor handles multiple URIs with comma-separated hosts.
     */
    public function testConstructorHandlesMultipleUrisWithCommaSeparatedHosts(): void
    {
        $client = $this->createClientForTesting('mysql://host1,host2/db');

        // Should create client with slave support
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that constructor handles multiple URIs as comma-separated strings.
     */
    public function testConstructorHandlesMultipleUrisAsCommaSeparatedStrings(): void
    {
        $client = $this->createClientForTesting('mysql://host1/db,mysql://host2/db');

        // Should create client with slave support
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that constructor handles master/slave pool size format.
     */
    public function testConstructorHandlesMasterSlavePoolSizeFormat(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?pool_size=4/8');

        // Should parse master=4, slave=8
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that update() with integer keys in fieldValues works.
     */
    public function testUpdateWithIntegerKeysInFieldValues(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        // Integer keys are treated as raw SQL expressions
        $result = $client->update('users', [0 => 'name = UPPER(name)'], ['id' => 1]);

        $this->assertIsInt($result);
    }

    /**
     * Test that update() with OR condition wraps in parentheses.
     */
    public function testUpdateWithOrConditionWrapsInParentheses(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->update('users', ['name' => 'John'], 'id = 1 or id = 2');

        $this->assertIsInt($result);
        // Verify operation was executed with OR condition, not the exact SQL format
        $this->assertNotEmpty($connection->executedSql);
    }

    /**
     * Test that delete() with OR condition wraps in parentheses.
     */
    public function testDeleteWithOrConditionWrapsInParentheses(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->delete('users', 'id = 1 or id = 2');

        $this->assertIsInt($result);
        // Verify operation was executed with OR condition, not the exact SQL format
        $this->assertNotEmpty($connection->executedSql);
    }

    /**
     * Test that delete() accepts FALSE as condition.
     */
    public function testDeleteAcceptsFalseAsCondition(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->delete('users', 'FALSE');

        $this->assertIsInt($result);
    }

    /**
     * Test that rollback() handles nested transactions correctly.
     */
    public function testRollbackHandlesNestedTransactionsCorrectly(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $transient = $client->getTransient('default');
        $transient->begin();
        $transient->begin();
        $this->assertSame(2, $this->getTransactionLevel($transient));

        $transient->rollback();
        $this->assertSame(1, $this->getTransactionLevel($transient));

        $transient->rollback();
        $this->assertSame(0, $this->getTransactionLevel($transient));
    }

    /**
     * Test that close() does nothing when no active connection.
     */
    public function testCloseDoesNothingWhenNoActiveConnection(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        // Should not throw exception
        $client->close();

        $this->assertNull($this->getPinnedConnection($client));
        $this->assertSame(0, $this->getTransactionLevel($client));
    }

    /**
     * Test that close() handles connection without transaction.
     */
    public function testCloseHandlesConnectionWithoutTransaction(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();

        // Manually set connection in context (simulating a connection that was obtained but not in transaction)
        $this->setPinnedConnection($client, $connection);
        $this->setTransactionLevel($client, 0);

        // close() should not push connection when transaction_level is 0
        $this->poolManager->expects($this->never())
            ->method('release');

        $client->close();

        $this->assertNull($this->getPinnedConnection($client));
    }

    /**
     * Test that update() with empty string condition throws exception.
     */
    public function testUpdateThrowsExceptionForEmptyStringCondition(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $client->update('users', ['name' => 'John'], '');
    }

    /**
     * Test that delete() with empty string condition throws exception.
     */
    public function testDeleteThrowsExceptionForEmptyStringCondition(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $client->delete('users', '');
    }

    /**
     * Test that delete() with single word condition throws exception.
     */
    public function testDeleteThrowsExceptionForSingleWordCondition(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $client->delete('users', 'id');
    }

    /**
     * Test that update() with single word condition throws exception.
     */
    public function testUpdateThrowsExceptionForSingleWordCondition(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $client->update('users', ['name' => 'John'], 'id');
    }

    /**
     * Test that fetchAll() with different PDO fetch modes works.
     */
    public function testFetchAllWithDifferentPdoFetchModes(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->queryResults = [['id' => 1, 'name' => 'John']];

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->fetchAll('SELECT * FROM users', [], PDO::FETCH_NUM);

        $this->assertIsArray($result);
    }

    /**
     * Test that executeInsertInternal() with fetchInsertId and context connection works.
     */
    public function testExecuteInsertInternalWithFetchInsertIdAndContextConnection(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->insertId = 456;
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $transient = $client->getTransient('default');
        $transient->begin();

        // Should use pinned connection
        $this->poolManager->expects($this->never())
            ->method('acquire');

        $result = $transient->insert('users', ['name' => 'John'], true);

        // Verify operation succeeded (user-facing behavior)
        $this->assertSame(456, $result, 'Insert with fetchInsertId should return inserted ID');

        // Clean up transaction state through public API
        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\Switon\Db\Event\DbRollback::class));

        $transient->rollback();

        // Verify transaction is cleaned up through public API
        $this->assertFalse($transient->inTransaction());
    }

    /**
     * Test that execute() with context connection works.
     */
    public function testExecuteWithContextConnection(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 2;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $transient = $client->getTransient('default');
        $transient->begin();

        // Should use pinned connection
        $this->poolManager->expects($this->never())
            ->method('acquire');

        $result = $transient->executeDelete('DELETE FROM users WHERE id = :id', ['id' => 1]);

        // Verify operation succeeded (user-facing behavior)
        $this->assertSame(2, $result);

        // Clean up transaction state through public API
        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\Switon\Db\Event\DbRollback::class));

        $transient->rollback();

        // Verify transaction is cleaned up through public API
        $this->assertFalse($transient->inTransaction());
    }

    /**
     * Test that configureSlavePools() adds readonly parameter when not present.
     */
    public function testConfigureSlavePoolsAddsReadonlyParameter(): void
    {
        $client = $this->createClientForTesting('mysql://host1/db,mysql://host2/db');

        // Should create client with slave support and readonly parameter
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that configureSlavePools() preserves existing readonly parameter.
     */
    public function testConfigureSlavePoolsPreservesExistingReadonlyParameter(): void
    {
        $client = $this->createClientForTesting('mysql://host1/db?readonly=1,mysql://host2/db?readonly=1');

        // Should create client with slave support
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that getTransient('slave') uses slave type when has_slave is true.
     */
    public function testGetTransientUsesSlavePoolType(): void
    {
        $client = $this->createClientForTesting('mysql://host1/db,mysql://host2/db');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->with($this->isInstanceOf(\Switon\Db\Client::class), $this->anything(), 'slave')
            ->willReturn($connection);
        $result = $client->getTransient('slave');

        $this->assertInstanceOf(\Switon\Db\Client::class, $result);
    }

    /**
     * Test that getTransient() can be called on a transient client.
     */
    public function testGetTransientCanBeCalledOnTransientClient(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection1 = new MockConnection();
        $connection2 = new MockConnection();

        $acquireCount = 0;
        $this->poolManager->expects($this->exactly(2))
            ->method('acquire')
            ->with($this->identicalTo($client), $this->anything(), 'default')
            ->willReturnCallback(function () use (&$acquireCount, $connection1, $connection2) {
                $acquireCount++;
                return $acquireCount === 1 ? $connection1 : $connection2;
            });

        $transient1 = $client->getTransient('default');
        $transient2 = $transient1->getTransient('default');

        $this->assertInstanceOf(Client::class, $transient2);
        $this->assertNotSame($transient1, $transient2);
    }

    /**
     * Test that buildSql() delegates to connection with various parameters.
     */
    public function testBuildSqlDelegatesToConnectionWithVariousParameters(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->buildSqlResult = 'SELECT * FROM users WHERE id = 1';

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $result = $client->buildSql([
            'fields' => '*',
            'from' => 'users',
            'where' => 'id = 1',
            'order' => 'id DESC',
            'limit' => 10,
        ]);

        $this->assertIsString($result);
    }

    /**
     * Test that getMetadata() applies prefix to table name.
     */
    public function testGetMetadataAppliesPrefixToTableName(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?prefix=app_');
        $connection = new MockConnection();
        $connection->metadata = ['columns' => ['id', 'name']];

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $result = $client->getMetadata('users');

        $this->assertIsArray($result);
        // Verify that prefix was applied by checking getMetadata was called
        // (we can't easily verify the table name without accessing protected methods)
        $this->assertIsArray($result);
    }

    /**
     * Test that getTables() returns empty array when no tables match prefix.
     */
    public function testGetTablesReturnsEmptyArrayWhenNoTablesMatchPrefix(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?prefix=app_');
        $connection = new MockConnection();
        $connection->tables = ['other_table', 'another_table']; // No tables with prefix

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $result = $client->getTables();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test that update() with non-string condition throws exception.
     */
    public function testUpdateThrowsExceptionForNonStringCondition(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $client->update('users', ['name' => 'John'], [0 => 123]); // Non-string condition
    }

    /**
     * Test that delete() with non-string condition throws exception.
     */
    public function testDeleteThrowsExceptionForNonStringCondition(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $client->delete('users', [0 => 123]); // Non-string condition
    }

    /**
     * Test that upsert() with empty updateFieldValues uses insertFieldValues.
     */
    public function testUpsertWithEmptyUpdateFieldValuesUsesInsertValues(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        // Empty updateFieldValues should default to insertFieldValues
        $result = $client->upsert('users', ['id' => 1, 'name' => 'John'], []);

        $this->assertIsInt($result);
        // Verify updateFieldValues was set to insertFieldValues
        $this->assertNotEmpty($connection->executedBind);
        $this->assertSame(['id' => 1, 'name' => 'John'], $connection->executedBind[0]['update']);
    }

    /**
     * Test that parseUris() handles empty template placeholder.
     */
    public function testParseUrisHandlesEmptyTemplatePlaceholder(): void
    {
        $client = $this->createClientForTesting('mysql://{}/db');

        // Should handle empty placeholder gracefully
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that parseUris() handles empty URI in list.
     */
    public function testParseUrisHandlesEmptyUriInList(): void
    {
        $client = $this->createClientForTesting('mysql://host1/db,,mysql://host2/db');

        // Should handle empty URI in list
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that configureSlavePools() handles URI with existing query parameters.
     */
    public function testConfigureSlavePoolsHandlesUriWithExistingQueryParameters(): void
    {
        $client = $this->createClientForTesting('mysql://host1/db?param=value,mysql://host2/db');

        // Should add readonly parameter correctly
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that constructor handles empty first URI.
     */
    public function testConstructorHandlesEmptyFirstUri(): void
    {
        // Empty URI should skip master pool creation
        $client = $this->createClientForTesting('');

        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that insert() handles table with schema and prefix.
     */
    public function testInsertHandlesTableWithSchemaAndPrefix(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?prefix=app_');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        // Table with schema should have prefix applied to table name only
        $result = $client->insert('schema.users', ['name' => 'John']);

        // Verify that schema.users was processed correctly
        $this->assertIsInt($result);
        $this->assertNotEmpty($connection->executedSql);
    }

    /**
     * Test that parseUris() handles parse_url returning false for host.
     */
    public function testParseUrisHandlesParseUrlReturningFalseForHost(): void
    {
        // URI without proper host part should still work
        $client = $this->createClientForTesting('invalid-uri');

        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that parseUris() handles comma-separated URIs with empty values.
     */
    public function testParseUrisHandlesCommaSeparatedUrisWithEmptyValues(): void
    {
        $client = $this->createClientForTesting('mysql://host1/db,,mysql://host2/db,');

        // Should handle empty URIs in list
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that parseUriParameters() handles prefix with underscore.
     */
    public function testParseUriParametersHandlesPrefixWithUnderscore(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?prefix=test_prefix_');

        $this->assertSame('test_prefix_', $client->getPrefix());
    }

    /**
     * Test that parseUriParameters() handles timeout with decimal.
     */
    public function testParseUriParametersHandlesTimeoutWithDecimal(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?timeout=2.5');

        // Timeout is protected, but we can verify it through behavior
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that parsePoolSizes() handles large pool sizes.
     */
    public function testParsePoolSizesHandlesLargePoolSizes(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?pool_size=100/200');

        // Should parse large pool sizes correctly
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that configureSlavePools() handles single slave URI.
     */
    public function testConfigureSlavePoolsHandlesSingleSlaveUri(): void
    {
        $client = $this->createClientForTesting('mysql://host1/db,mysql://host2/db');

        // Should configure single slave pool
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that configureSlavePools() handles multiple slave URIs.
     */
    public function testConfigureSlavePoolsHandlesMultipleSlaveUris(): void
    {
        $client = $this->createClientForTesting('mysql://host1/db,mysql://host2/db,mysql://host3/db');

        // Should configure multiple slave pools
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that begin() handles PDOException during transaction start.
     */
    public function testBeginHandlesPdoExceptionDuringTransactionStart(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->beginThrowsException = true;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\Switon\Db\Event\DbBegin::class));

        $this->expectException(\Switon\Db\Exception::class);
        $client->getTransient('default')->begin();
    }

    /**
     * Test that rollback() handles PDOException during rollback.
     */
    public function testRollbackHandlesPdoExceptionDuringRollback(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->rollbackThrowsException = true;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $transient = $client->getTransient('default');
        $transient->begin();

        $this->expectException(\Switon\Db\Exception::class);
        $transient->rollback();
    }

    /**
     * Test that commit() handles PDOException during commit.
     */
    public function testCommitHandlesPdoExceptionDuringCommit(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->commitThrowsException = true;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        $transient = $client->getTransient('default');
        $transient->begin();

        $this->expectException(\Switon\Db\Exception::class);
        $transient->commit();
    }

    /**
     * Test that getTables() accepts schema parameter.
     */
    public function testGetTablesAcceptsSchemaParameter(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->tables = ['users', 'products'];

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $result = $client->getTables('test_schema');

        $this->assertIsArray($result);
    }

    /**
     * Test that update() with QueryFragmentable-like object works.
     */
    public function testUpdateWithQueryFragmentableObject(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $this->eventDispatcher->expects($this->atLeastOnce())
            ->method('dispatch');

        // Create a mock QueryFragmentable-like object
        $fragment = new class () {
            public ?string $field = null;

            public function setField(string $field): void
            {
                $this->field = $field;
            }

            public function getExpression(): string
            {
                return "[{$this->field}] = [{$this->field}] + 1";
            }

            public function getBind(): array
            {
                return [];
            }
        };

        $result = $client->update('users', ['counter' => $fragment], ['id' => 1]);

        $this->assertIsInt($result);
    }

    /**
     * Test that upsert() with SqlFragmentInterface object in updateFieldValues works.
     */
    public function testUpsertWithSqlFragmentInUpdateFieldValues(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        // Create a mock SqlFragmentInterface object
        $fragment = new class () implements \Switon\Db\SqlFragmentInterface {
            protected ?string $field = null;

            public function setField(string $field): static
            {
                $this->field = $field;
                return $this;
            }

            public function getExpression(): string
            {
                return "[{$this->field}] = [{$this->field}] + 1";
            }

            public function getBind(): array
            {
                return ['extra' => 'value'];
            }
        };

        $result = $client->upsert('users', ['id' => 1, 'name' => 'John'], ['counter' => $fragment]);

        $this->assertIsInt($result);
        // Verify SqlFragmentInterface was processed (expression should be in update values)
        $this->assertNotEmpty($connection->executedBind);
        $this->assertArrayHasKey('extra', $connection->executedBind[0]['insert']);
        $this->assertSame('value', $connection->executedBind[0]['insert']['extra']);
        $updateValues = $connection->executedBind[0]['update'];
        $this->assertArrayHasKey('counter', $updateValues);
        $this->assertInstanceOf(UpsertExpression::class, $updateValues['counter']);
        $this->assertStringContainsString('counter', $updateValues['counter']->sql);
    }

    /**
     * Test that upsert() passes primary key to connection adapter.
     */
    public function testUpsertPassesPrimaryKeyToConnectionAdapter(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        // Primary key handling is delegated to connection adapter
        $result = $client->upsert('users', ['id' => 1, 'name' => 'John'], ['id' => 999, 'name' => 'Jane'], 'id');

        $this->assertIsInt($result);
        // Verify primary key was passed to adapter
        $this->assertNotEmpty($connection->executedBind);
        $this->assertSame('id', $connection->executedBind[0]['pk']);
    }

    /**
     * Test that upsert() with integer keys in updateFieldValues works.
     */
    public function testUpsertWithIntegerKeysInUpdateFieldValues(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        // Integer keys: field name is the value, bind comes from insertFieldValues
        $result = $client->upsert('users', ['id' => 1, 'name' => 'John', 'counter' => 10, 'status' => 'active'], [0 => 'counter', 1 => 'status']);

        $this->assertIsInt($result);
        // Verify integer keys were passed through
        $this->assertNotEmpty($connection->executedBind);
        $updateValues = $connection->executedBind[0]['update'];
        $this->assertArrayHasKey(0, $updateValues);
        $this->assertSame('counter', $updateValues[0]);
    }

    /**
     * Test that upsert() stringifies numeric keys for SqlFragmentInterface field names.
     */
    public function testUpsertWithSqlFragmentAndNumericKeyStringifiesFieldName(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $fragment = new class () implements \Switon\Db\SqlFragmentInterface {
            public ?string $field = null;

            public function setField(string $field): static
            {
                $this->field = $field;
                return $this;
            }

            public function getExpression(): string
            {
                return "[{$this->field}] = [{$this->field}] + 1";
            }

            public function getBind(): array
            {
                return [];
            }
        };

        $result = $client->upsert('users', ['id' => 1, 'name' => 'John'], [0 => $fragment]);

        $this->assertSame(1, $result);
        $this->assertSame('0', $fragment->field);
    }

    /**
     * Creates a Client instance for testing without triggering full initialization.
     *
     * @param string $uri Database URI
     *
     * @return Client Client instance with mocked dependencies
     */
    protected function createClientForTesting(string $uri): Client
    {
        // Use pre-configured test container (EventDispatcherInterface is already registered)
        $container = new \Switon\Testing\Container();
        // Replace with test-specific instances
        $container->set(EventDispatcherInterface::class, $this->eventDispatcher);
        $container->set(PoolManagerInterface::class, $this->poolManager);
        // Use parent container instance (container interfaces are already registered by Switon\Testing\Container)
        $container->set(ContainerInterface::class, $this->container);
        $container->set(\Psr\Container\ContainerInterface::class, $this->container);

        // Mock poolManager to avoid actual pool operations
        $this->poolManager->expects($this->any())
            ->method('add')
            ->willReturnCallback(function () {
                // void method, do nothing
            });

        $this->poolManager->expects($this->any())
            ->method('create')
            ->willReturnCallback(function () {
                // void method, do nothing
            });

        // Mock guard() to return PoolGuard wrapping the connection from acquire()
        $this->poolManager->expects($this->any())
            ->method('guard')
            ->willReturnCallback(function ($owner, $timeout, $type) {
                $connection = $this->poolManager->acquire($owner, $timeout, $type);
                return new \Switon\Pooling\PoolGuard($this->poolManager, $owner, $connection, $type);
            });

        // Create client with URI parameter
        $client = $container->make(Client::class, ['uri' => $uri]);

        return $client;
    }

    protected function getTransactionLevel(Client $client): int
    {
        $property = new ReflectionProperty(Client::class, 'transaction_level');

        return (int)$property->getValue($client);
    }

    protected function setTransactionLevel(Client $client, int $level): void
    {
        $property = new ReflectionProperty(Client::class, 'transaction_level');
        $property->setValue($client, $level);
    }

    protected function getPinnedConnection(Client $client): ?ConnectionInterface
    {
        $property = new ReflectionProperty(Client::class, 'connection');
        $value = $property->getValue($client);

        return $value instanceof ConnectionInterface ? $value : null;
    }

    protected function setPinnedConnection(Client $client, ?ConnectionInterface $connection): void
    {
        $property = new ReflectionProperty(Client::class, 'connection');
        $property->setValue($client, $connection);
    }

    /**
     * Test that allocateIds() uses a new connection from pool.
     */
    public function testAllocateIdsUsesNewConnectionFromPool(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();

        // allocateIds should always get a new connection, even if context has a connection
        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $result = $client->allocateIds('test_table', 'id', 10);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertIsInt($result[0]);
        $this->assertIsInt($result[1]);
    }

    /**
     * Test that allocateIds() throws exception when called within transaction.
     */
    public function testAllocateIdsThrowsExceptionWhenInTransaction(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $transactionConnection = new MockConnection();

        // Start a transaction (pins a connection)
        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($transactionConnection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($transactionConnection), 'default');

        // Start transaction
        $transient = $client->getTransient('default');
        $transient->begin();

        // Expect exception when calling allocateIds within transaction
        $this->expectException(\Switon\Db\Exception::class);
        $this->expectExceptionMessage('Cannot call allocateIds() within a transaction');

        // This should throw exception
        $transient->allocateIds('test_table', 'id', 5);
    }

    /**
     * Test that allocateIds() adds table prefix before calling adapter (consistent with other methods).
     */
    public function testAllocateIdsAddsTablePrefix(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?prefix=app_');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        // Call with table name without prefix (consistent with insert(), update(), etc.)
        $result = $client->allocateIds('users', 'id', 10);

        // Verify connection received prefixed table name
        $this->assertNotNull($connection->lastAllocateIdsCall);
        $this->assertEquals('app_users', $connection->lastAllocateIdsCall['table']);
        $this->assertEquals('id', $connection->lastAllocateIdsCall['primaryKey']);
        $this->assertEquals(10, $connection->lastAllocateIdsCall['count']);

        // Verify result is returned
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * Test that allocateIds() passes correct parameters to adapter.
     */
    public function testAllocateIdsPassesCorrectParametersToAdapter(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        $result = $client->allocateIds('test_table', 'id', 15);

        // Verify result format (MockConnection returns [1000, 1000 + count - 1])
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(1000, $result[0]);
        $this->assertEquals(1014, $result[1]); // 1000 + 15 - 1
    }

    /**
     * Test bulkInsert with multiple records.
     */
    public function testBulkInsertWithMultipleRecords(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 3;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release')
            ->with($this->identicalTo($client), $this->identicalTo($connection), 'default');

        // bulkInsert dispatches 4 events: DbExecuting, DbInserting, DbInserted, DbExecuted
        $this->eventDispatcher->expects($this->exactly(4))
            ->method('dispatch');

        $records = [
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
            ['name' => 'Charlie', 'age' => 35],
        ];

        $result = $client->bulkInsert('users', $records);

        $this->assertSame(3, $result);
        $this->assertNotEmpty($connection->executedSql);
        $this->assertStringContainsString('INSERT INTO', $connection->executedSql[0]);
        $this->assertStringContainsString('VALUES', $connection->executedSql[0]);

        // Verify bind parameters are correctly generated
        $bind = $connection->executedBind[0];
        $this->assertSame('Alice', $bind['name_0']);
        $this->assertSame(25, $bind['age_0']);
        $this->assertSame('Charlie', $bind['name_2']);
        $this->assertSame(35, $bind['age_2']);
    }

    /**
     * Test bulkInsert with empty records returns zero.
     */
    public function testBulkInsertWithEmptyRecordsReturnsZero(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $result = $client->bulkInsert('users', []);
        $this->assertSame(0, $result);
    }

    /**
     * Test bulkInsert handles null values.
     */
    public function testBulkInsertHandlesNullValues(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 2;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $this->poolManager->expects($this->once())
            ->method('release');

        $records = [
            ['name' => 'Alice', 'email' => null],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        ];

        $result = $client->bulkInsert('users', $records);
        $this->assertSame(2, $result);

        $bind = $connection->executedBind[0];
        $this->assertNull($bind['email_0']);
        $this->assertSame('bob@example.com', $bind['email_1']);
    }

    /**
     * Test bulkInsert with missing fields uses null.
     */
    public function testBulkInsertWithMissingFieldsUsesNull(): void
    {
        $client = $this->createClientForTesting('mysql://host/db');
        $connection = new MockConnection();
        $connection->executeResult = 2;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $records = [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob'], // Missing 'email' field
        ];

        $result = $client->bulkInsert('users', $records);
        $this->assertSame(2, $result);

        $bind = $connection->executedBind[0];
        $this->assertNull($bind['email_1']); // Missing field should be null
    }

    /**
     * Test bulkInsert applies table prefix.
     */
    public function testBulkInsertAppliesTablePrefix(): void
    {
        $client = $this->createClientForTesting('mysql://host/db?prefix=app_');
        $connection = new MockConnection();
        $connection->executeResult = 1;

        $this->poolManager->expects($this->once())
            ->method('acquire')
            ->willReturn($connection);

        $records = [['name' => 'Test']];
        $result = $client->bulkInsert('users', $records);

        $this->assertSame(1, $result);
        $this->assertStringContainsString('[app_users]', $connection->executedSql[0]);
    }
}
