<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit\Inspector;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Db\ClientInterface;
use Switon\Db\Event\DbBegin;
use Switon\Db\Event\DbCommit;
use Switon\Db\Event\DbExecuted;
use Switon\Db\Event\DbExecuting;
use Switon\Db\Event\DbQueried;
use Switon\Db\Event\DbQuerying;
use Switon\Db\Event\DbRollback;
use Switon\Db\Inspector\SqlCollector;
use Switon\Db\Inspector\SqlCollectorContext;
use Switon\Db\PreparedEmulatorInterface;
use Switon\Db\Tests\TestCase;
use Switon\Eventing\ListenerProviderInterface;

#[AllowMockObjectsWithoutExpectations]
class SqlCollectorTest extends TestCase
{
    protected SqlCollector $collector;
    protected PreparedEmulatorInterface $preparedEmulator;
    protected ClientInterface $dbClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparedEmulator = $this->createMock(PreparedEmulatorInterface::class);
        $this->container->set(PreparedEmulatorInterface::class, $this->preparedEmulator);

        $this->dbClient = $this->createMock(ClientInterface::class);
        $this->container->set(ClientInterface::class, $this->dbClient);

        $this->collector = $this->container->make(SqlCollector::class);
        $this->injector->inject($this);
    }

    public function testGetContextReturnsSqlCollectorContext(): void
    {
        // Act
        $context = $this->collector->getContext();

        // Assert
        $this->assertInstanceOf(SqlCollectorContext::class, $context);
    }

    public function testOnDbRecordsQueryingEvent(): void
    {
        // Arrange
        $sql = 'SELECT * FROM users WHERE id = ?';
        $bind = [1];
        $event = new DbQuerying($this->dbClient, $sql, $bind);

        $this->preparedEmulator->method('emulate')
            ->with($sql, $bind, 128)
            ->willReturn('SELECT * FROM users WHERE id = 1');

        // Act
        $this->collector->onDb($event);

        // Assert
        $context = $this->collector->getContext();
        $this->assertSame(1, $context->count);
        $this->assertCount(1, $context->executed);
        $this->assertSame($sql, $context->executed[0]['prepared']);
        $this->assertSame($bind, $context->executed[0]['bind']);
        $this->assertSame('SELECT * FROM users WHERE id = 1', $context->executed[0]['emulated']);
        $this->assertSame(1, $context->prepared[$sql]);
    }

    public function testOnDbRecordsExecutingEvent(): void
    {
        // Arrange
        $sql = 'UPDATE users SET name = ? WHERE id = ?';
        $bind = ['John', 1];
        $event = new DbExecuting($this->dbClient, 'update', $sql, $bind);

        $this->preparedEmulator->method('emulate')
            ->willReturn("UPDATE users SET name = 'John' WHERE id = 1");

        // Act
        $this->collector->onDb($event);

        // Assert
        $context = $this->collector->getContext();
        $this->assertSame(1, $context->count);
        $this->assertCount(1, $context->executed);
        $this->assertSame($sql, $context->executed[0]['prepared']);
    }

    public function testOnDbRecordsQueriedEventWithElapsedAndRowCount(): void
    {
        // Arrange
        $sql = 'SELECT * FROM users';
        $queryingEvent = new DbQuerying($this->dbClient, $sql, []);
        $queriedEvent = new DbQueried($this->dbClient, $sql, [], 10, [], 0.005);

        $this->preparedEmulator->method('emulate')
            ->willReturn($sql);

        // Act
        $this->collector->onDb($queryingEvent);
        $this->collector->onDb($queriedEvent);

        // Assert
        $context = $this->collector->getContext();
        $this->assertSame(0.005, $context->executed[0]['elapsed']);
        $this->assertSame(10, $context->executed[0]['row_count']);
    }

    public function testOnDbRecordsExecutedEventWithElapsedAndRowCount(): void
    {
        // Arrange
        $sql = 'DELETE FROM users WHERE id = ?';
        $executingEvent = new DbExecuting($this->dbClient, 'delete', $sql, [1]);
        $executedEvent = new DbExecuted($this->dbClient, 'delete', $sql, [1], 1, 0.003);

        $this->preparedEmulator->method('emulate')
            ->willReturn('DELETE FROM users WHERE id = 1');

        // Act
        $this->collector->onDb($executingEvent);
        $this->collector->onDb($executedEvent);

        // Assert
        $context = $this->collector->getContext();
        $this->assertSame(0.003, $context->executed[0]['elapsed']);
        $this->assertSame(1, $context->executed[0]['row_count']);
    }

    public function testOnDbRecordsBeginTransaction(): void
    {
        // Arrange
        $event = new DbBegin($this->dbClient);

        // Act
        $this->collector->onDb($event);

        // Assert
        $context = $this->collector->getContext();
        $this->assertSame(1, $context->count);
        $this->assertCount(1, $context->executed);
        $this->assertSame(DbBegin::class, $context->executed[0]['prepared']);
        $this->assertSame(DbBegin::class, $context->executed[0]['emulated']);
        $this->assertSame(0, $context->executed[0]['elapsed']);
        $this->assertSame(0, $context->executed[0]['row_count']);
    }

    public function testOnDbRecordsCommitTransaction(): void
    {
        // Arrange
        $event = new DbCommit($this->dbClient);

        // Act
        $this->collector->onDb($event);

        // Assert
        $context = $this->collector->getContext();
        $this->assertSame(DbCommit::class, $context->executed[0]['prepared']);
        $this->assertSame(1, $context->prepared[DbCommit::class]);
    }

    public function testOnDbRecordsRollbackTransaction(): void
    {
        // Arrange
        $event = new DbRollback($this->dbClient);

        // Act
        $this->collector->onDb($event);

        // Assert
        $context = $this->collector->getContext();
        $this->assertSame(DbRollback::class, $context->executed[0]['prepared']);
        $this->assertSame(1, $context->prepared[DbRollback::class]);
    }

    public function testOnDbCountsDuplicatePreparedStatements(): void
    {
        // Arrange
        $sql = 'SELECT * FROM users WHERE id = ?';

        $this->preparedEmulator->method('emulate')
            ->willReturn('SELECT * FROM users WHERE id = 1');

        // Act
        $this->collector->onDb(new DbQuerying($this->dbClient, $sql, [1]));
        $this->collector->onDb(new DbQueried($this->dbClient, $sql, [1], 1, [], 0.001));
        $this->collector->onDb(new DbQuerying($this->dbClient, $sql, [2]));
        $this->collector->onDb(new DbQueried($this->dbClient, $sql, [2], 1, [], 0.001));
        $this->collector->onDb(new DbQuerying($this->dbClient, $sql, [3]));
        $this->collector->onDb(new DbQueried($this->dbClient, $sql, [3], 1, [], 0.001));

        // Assert
        $context = $this->collector->getContext();
        $this->assertSame(3, $context->prepared[$sql]);
        $this->assertSame(3, $context->count);
    }

    public function testCollectReturnsAllData(): void
    {
        // Arrange
        $sql = 'SELECT 1';
        $this->preparedEmulator->method('emulate')->willReturn($sql);
        $this->collector->onDb(new DbQuerying($this->dbClient, $sql, []));
        $this->collector->onDb(new DbQueried($this->dbClient, $sql, [], 1, [], 0.001));

        // Act
        $result = $this->collector->collect();

        // Assert
        $this->assertArrayHasKey('prepared', $result);
        $this->assertArrayHasKey('executed', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertSame(1, $result['count']);
    }

    public function testJsonSerializeReturnsEmptyArray(): void
    {
        // Act
        $result = $this->collector->jsonSerialize();

        // Assert
        $this->assertSame([], $result);
    }

    public function testBootRegistersCollectorWithListenerProvider(): void
    {
        $listener = $this->createMock(ListenerProviderInterface::class);
        $listener->expects($this->once())
            ->method('register')
            ->with($this->isInstanceOf(SqlCollector::class));

        $this->container->replace(ListenerProviderInterface::class, $listener);

        $collector = $this->container->make(SqlCollector::class);
        $collector->boot();
    }

    public function testOnDbIncrementsPreparedCountWhenSameTransactionEventRepeats(): void
    {
        $this->collector->onDb(new DbBegin($this->dbClient));
        $this->collector->onDb(new DbBegin($this->dbClient));

        $context = $this->collector->getContext();

        $this->assertSame(2, $context->prepared[DbBegin::class]);
        $this->assertSame(2, $context->count);
        $this->assertCount(2, $context->executed);
    }
}
