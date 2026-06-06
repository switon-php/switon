<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Db\Client;
use Switon\Db\ClientInterface;
use Switon\Db\ConnectionInterface;
use Switon\Db\PoolConfiguration;
use Switon\Db\PoolConfigurator;
use Switon\Db\PoolConfiguratorInterface;
use Switon\Db\Tests\TestCase;
use Switon\Pooling\PoolGuard;
use Switon\Pooling\PoolManagerInterface;
use PDOException;

/**
 * Unit tests for Client.
 *
 * Focuses on pure logic and branch behavior without real database connections.
 */
#[AllowMockObjectsWithoutExpectations]
class ClientTest extends TestCase
{
    protected EventDispatcherInterface|MockObject $eventDispatcher;
    protected PoolManagerInterface|MockObject $poolManager;
    protected PoolConfigurator|MockObject $poolConfigurator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->poolManager = $this->createMock(PoolManagerInterface::class);
        $this->poolConfigurator = $this->createMock(PoolConfigurator::class);

        $this->container->set(EventDispatcherInterface::class, $this->eventDispatcher);
        $this->container->set(PoolManagerInterface::class, $this->poolManager);
        $this->container->set(PoolConfigurator::class, $this->poolConfigurator);
        $this->container->set(PoolConfiguratorInterface::class, $this->poolConfigurator);
    }

    public function testGetTransientAutoUsesSlaveWhenHasSlaveTrue(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: true));

        $connection = $this->createMock(ConnectionInterface::class);
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'slave');

        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'slave')
            ->willReturn($guard);
        // Act
        // Act

        $transient = $client->getTransient('auto');
        // Assert

        $this->assertInstanceOf(Client::class, $transient);
        $this->assertNotSame($client, $transient);
    }

    public function testGetTransientSlaveFallsBackToDefaultWhenNoSlave(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');

        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($guard);
        // Act
        // Act

        $client->getTransient('slave');
    }

    public function testFetchAllUsesSlavePoolWhenHasSlaveTrue(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: true));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('convertQuotes')->willReturnCallback(fn (string $sql) => $sql);
        $connection->expects($this->once())
            ->method('query')
            ->with('SELECT * FROM [users]', [], PDO::FETCH_ASSOC)
            ->willReturn([]);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'slave');

        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'slave')
            ->willReturn($guard);

        $result = $client->fetchAll('SELECT * FROM [users]');
        // Assert

        $this->assertSame([], $result);
    }

    public function testGetTablesStripsPrefix(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('getTables')
            ->with(null)
            ->willReturn(['app_users', 'app_roles', 'other_table']);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');

        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($guard);

        $tables = $client->getTables();
        // Assert

        $this->assertSame(['users', 'roles'], $tables);
    }

    public function testDeleteAllowsWhereFalseKeyword(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('convertQuotes')->willReturnCallback(fn (string $sql) => $sql);
        $connection->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('DELETE FROM [users] WHERE FALSE'),
                []
            )
            ->willReturn(0);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);
        // Act
        // Act

        $transient = $client->getTransient();
        $affected = $transient->delete('users', 'FALSE');
        // Assert

        $this->assertSame(0, $affected);
    }

    public function testUpdateThrowsExceptionForNumericConditionWithNonStringValue(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        // Act & Assert

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $transient->update('users', ['name' => 'John'], [0 => 123]);
    }

    public function testBulkInsertBuildsPlaceholderGroupsAndBinds(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('convertQuotes')->willReturnCallback(fn (string $sql) => $sql);

        $capturedSql = null;
        $capturedBind = null;

        $connection->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $bind) use (&$capturedSql, &$capturedBind) {
                $capturedSql = $sql;
                $capturedBind = $bind;
                return 2;
            });

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        // Act
        $count = $transient->bulkInsert('users', [
            ['name' => 'John', 'age' => 20],
            ['name' => 'Jane', 'age' => 30],
        ]);
        // Assert

        $this->assertSame(2, $count);
        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('INSERT INTO [app_users]', (string)$capturedSql);
        $this->assertStringContainsString('(:name_0,:age_0)', (string)$capturedSql);
        $this->assertStringContainsString('(:name_1,:age_1)', (string)$capturedSql);

        $this->assertSame(
            [
                'name_0' => 'John',
                'age_0' => 20,
                'name_1' => 'Jane',
                'age_1' => 30,
            ],
            $capturedBind
        );
    }

    public function testAllocateIdsThrowsWhenInTransaction(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('begin');
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        $transient->begin();
        // Act & Assert

        $this->expectException(\Switon\Db\Exception::class);
        $transient->allocateIds('users', 'id', 10);
    }

    public function testUpdateBySqlAppliesPrefixAndDelegatesToExecuteUpdate(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('convertQuotes')->willReturnCallback(fn (string $sql) => $sql);
        $connection->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE [app_users] SET name = :name WHERE id = :id'),
                ['name' => 'John', 'id' => 1]
            )
            ->willReturn(1);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($guard);

        $affected = $client->updateBySql('users', 'name = :name WHERE id = :id', ['name' => 'John', 'id' => 1]);
        // Assert

        $this->assertSame(1, $affected);
    }

    public function testDeleteBySqlAppliesPrefixAndDelegatesToExecuteDelete(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('convertQuotes')->willReturnCallback(fn (string $sql) => $sql);
        $connection->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('DELETE FROM [app_users] WHERE id = :id'),
                ['id' => 1]
            )
            ->willReturn(1);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($guard);

        $affected = $client->deleteBySql('users', 'id = :id', ['id' => 1]);
        // Assert

        $this->assertSame(1, $affected);
    }

    public function testInsertBySqlAppliesPrefixAndDelegatesToExecuteInsert(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('convertQuotes')->willReturnCallback(fn (string $sql) => $sql);
        $connection->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('INSERT INTO [app_users] SELECT * FROM [tmp_users]'),
                ['x' => 1]
            )
            ->willReturn(1);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($guard);

        $affected = $client->insertBySql('users', 'SELECT * FROM [tmp_users] WHERE [x]=:x', ['x' => 1]);
        // Assert

        $this->assertSame(1, $affected);
    }

    public function testGetMetadataWrapsTableNameWithPrefixAndDispatchesEvent(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));

        $expectedMeta = [
            Client::METADATA_ATTRIBUTES => ['id' => 'int'],
            Client::METADATA_PRIMARY_KEY => ['id'],
            Client::METADATA_AUTO_INCREMENT_KEY => 'id',
            Client::METADATA_INT_TYPE_ATTRIBUTES => ['id'],
        ];

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('getMetadata')
            ->with('[app_users]')
            ->willReturn($expectedMeta);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($guard);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof \Switon\Db\Event\DbMetadata && $event->table === '[app_users]';
            }));
        // Act

        $meta = $client->getMetadata('users');
        // Assert

        $this->assertSame($expectedMeta, $meta);
    }

    public function testCloseRollsBackTransactionWhenTransientInTransaction(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('begin');
        $connection->expects($this->once())->method('rollback');

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        // Use public API to enter transaction state
        $transient = $client->getTransient();
        $transient->begin();
        // Act
        $this->assertTrue($transient->inTransaction());
        // Act

        // close() should rollback and clean up
        $transient->close();
        // Assert

        $this->assertFalse($transient->inTransaction());
    }

    public function testBeginThrowsExceptionWhenNotTransient(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));
        // Act & Assert

        $this->expectException(\Switon\Db\Exception\TransientClientException::class);
        $client->begin();
    }

    public function testCommitThrowsExceptionWhenNoTransaction(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        // Act & Assert

        $this->expectException(\Switon\Db\Exception\NoActiveTransactionException::class);
        $transient->commit();
    }

    public function testRollbackWithoutTransactionReturnsEarly(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();

        // Should not throw and should not call connection rollback.
        $connection->expects($this->never())->method('rollback');
        $transient->rollback();
        // Assert

        $this->assertFalse($transient->inTransaction());
    }

    public function testUpsertThrowsWhenSqlFragmentHasNumericKey(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();

        $fragment = new class () implements \Switon\Db\SqlFragmentInterface {
            protected string $field = '';

            public function setField(string $field): static
            {
                $this->field = $field;
                return $this;
            }

            public function getExpression(): string
            {
                return "{$this->field} = {$this->field} + :{$this->field}";
            }

            public function getBind(): array
            {
                return [$this->field => 1];
            }
        };
        // Act & Assert

        $this->expectException(\Switon\Db\Exception::class);
        $transient->upsert('users', ['id' => 1, 'hits' => 1], [0 => $fragment]);
    }

    public function testUpsertThrowsWhenFragmentBindConflictsWithInsertValues(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();

        $fragment = new class () implements \Switon\Db\SqlFragmentInterface {
            protected string $field = '';

            public function setField(string $field): static
            {
                $this->field = $field;
                return $this;
            }

            public function getExpression(): string
            {
                return "{$this->field} = {$this->field} + :{$this->field}";
            }

            public function getBind(): array
            {
                // Will conflict with insertFieldValues['hits'] if it's different
                return [$this->field => 999];
            }
        };
        // Act & Assert

        $this->expectException(\Switon\Db\Exception::class);
        $transient->upsert('users', ['id' => 1, 'hits' => 1], ['hits' => $fragment]);
    }

    public function testUpsertDefaultsUpdateValuesToInsertValuesWhenEmpty(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('upsert')
            ->with(
                '[users]',
                ['id' => 1, 'name' => 'John'],
                ['id' => 1, 'name' => 'John'],
                'id'
            )
            ->willReturn(1);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        $count = $transient->upsert('users', ['id' => 1, 'name' => 'John'], []);
        // Assert

        $this->assertSame(1, $count);
    }

    public function testBeginWrapsPdoExceptionAsDbException(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('begin')
            ->willThrowException(new PDOException('begin failed'));

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        // Act & Assert

        $this->expectException(\Switon\Db\Exception::class);
        $transient->begin();
    }

    public function testCommitWrapsPdoExceptionAsDbException(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('begin');
        $connection->expects($this->once())
            ->method('commit')
            ->willThrowException(new PDOException('commit failed'));

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        $transient->begin();
        // Act & Assert

        $this->expectException(\Switon\Db\Exception::class);
        $transient->commit();
    }

    public function testRollbackWrapsPdoExceptionAsDbException(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('begin');
        $connection->expects($this->once())
            ->method('rollback')
            ->willThrowException(new PDOException('rollback failed'));

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        $transient->begin();
        // Act & Assert

        $this->expectException(\Switon\Db\Exception::class);
        $transient->rollback();
    }

    public function testGetPrefixReturnsConfiguredPrefix(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));
        // Act
        $prefix = $client->getPrefix();
        // Assert
        $this->assertSame('app_', $prefix);
    }

    public function testExecuteInsertDelegatesToConnectionExecute(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('execute')
            ->with('INSERT INTO [users] ([name]) VALUES (:name)', ['name' => 'John'])
            ->willReturn(1);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($guard);
        // Act

        $count = $client->executeInsert('INSERT INTO [users] ([name]) VALUES (:name)', ['name' => 'John']);
        // Assert

        $this->assertSame(1, $count);
    }

    public function testExecuteUpdateDelegatesToConnectionExecute(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('execute')
            ->with('UPDATE [users] SET [name]=:name WHERE [id]=:id', ['name' => 'John', 'id' => 1])
            ->willReturn(1);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($guard);
        // Act

        $affected = $client->executeUpdate('UPDATE [users] SET [name]=:name WHERE [id]=:id', ['name' => 'John', 'id' => 1]);
        // Assert

        $this->assertSame(1, $affected);
    }

    public function testExecuteDeleteDelegatesToConnectionExecute(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('execute')
            ->with('DELETE FROM [users] WHERE [id]=:id', ['id' => 1])
            ->willReturn(1);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($guard);
        // Act

        $affected = $client->executeDelete('DELETE FROM [users] WHERE [id]=:id', ['id' => 1]);
        // Assert

        $this->assertSame(1, $affected);
    }

    public function testInsertReturnsZeroForEmptyRecord(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        // Act
        $count = $transient->insert('users', []);
        // Assert
        $this->assertSame(0, $count);
    }

    public function testInsertFetchInsertIdTrueReturnsExecuteResult(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('execute')
            ->with(
                'INSERT INTO [app_users] ([name]) VALUES (:name)',
                ['name' => 'John'],
                true
            )
            ->willReturn(123);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        // Act
        $id = $transient->insert('users', ['name' => 'John'], true);
        // Assert

        $this->assertSame(123, $id);
    }

    public function testBulkInsertReturnsZeroWhenRecordsEmpty(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        // Act
        $count = $transient->bulkInsert('users', []);
        // Assert
        $this->assertSame(0, $count);
    }

    public function testUpdateReturnsZeroWhenFieldValuesEmpty(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        // Act
        $count = $transient->update('users', [], ['id' => 1]);
        // Assert
        $this->assertSame(0, $count);
    }

    public function testUpdateThrowsUnsafeOperationWhenConditionsEmpty(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        // Act & Assert

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $transient->update('users', ['name' => 'John'], '');
    }

    public function testUpdateWrapsOrConditionInParentheses(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $bind) {
                $this->assertStringContainsString(' WHERE (id = 1 OR id = 2)', $sql);
                $this->assertSame(['name' => 'John'], $bind);
                return 1;
            });

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        $affected = $transient->update('users', ['name' => 'John'], ['id = 1 OR id = 2']);
        // Assert

        $this->assertSame(1, $affected);
    }

    public function testUpdateSupportsSqlFragmentInSetValues(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $fragment = new class () implements \Switon\Db\SqlFragmentInterface {
            protected string $field = '';

            public function setField(string $field): static
            {
                $this->field = $field;
                return $this;
            }

            public function getExpression(): string
            {
                return "[{$this->field}] = [{$this->field}] + :inc";
            }

            public function getBind(): array
            {
                return ['inc' => 1];
            }
        };

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $bind) {
                $this->assertStringContainsString('SET [hits] = [hits] + :inc', $sql);
                $this->assertStringContainsString('WHERE [id]=:id', $sql);
                $this->assertSame(['id' => 1, 'inc' => 1], $bind);
                return 1;
            });

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        $affected = $transient->update('users', ['hits' => $fragment], ['id' => 1]);
        // Assert

        $this->assertSame(1, $affected);
    }

    public function testDeleteThrowsUnsafeOperationForSimpleIdentifierCondition(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        // Act & Assert

        $this->expectException(\Switon\Db\Exception\UnsafeOperationException::class);
        $transient->delete('users', 'id');
    }

    public function testDeleteWrapsOrConditionInParentheses(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $bind) {
                $this->assertStringContainsString('DELETE FROM [users] WHERE (id = 1 OR id = 2)', $sql);
                $this->assertSame([], $bind);
                return 1;
            });

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        $affected = $transient->delete('users', ['id = 1 OR id = 2']);
        // Assert

        $this->assertSame(1, $affected);
    }

    public function testGetTablesReturnsAsIsWhenPrefixEmpty(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('getTables')
            ->with('dbo')
            ->willReturn(['users', 'roles']);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($guard);
        // Act
        $tables = $client->getTables('dbo');

        // Assert
        $this->assertSame(['users', 'roles'], $tables);
    }

    public function testBuildSqlDelegatesToConnection(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('buildSql')
            ->with(['fields' => '*', 'from' => 'users'])
            ->willReturn('SELECT * FROM users');

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($guard);
        // Act
        $sql = $client->buildSql(['fields' => '*', 'from' => 'users']);

        // Assert
        $this->assertSame('SELECT * FROM users', $sql);
    }

    public function testAllocateIdsUsesSeparateMasterConnectionAndAppliesPrefix(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: 'app_', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('allocateIds')
            ->with('app_users', 'id', 10)
            ->willReturn([100, 109]);

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');

        $this->poolManager->expects($this->once())
            ->method('guard')
            ->with($this->identicalTo($client), $this->isFloat(), 'default')
            ->willReturn($guard);
        // Act
        $ids = $client->allocateIds('users', 'id', 10);

        // Assert
        $this->assertSame([100, 109], $ids);
    }

    public function testCloseDoesNotReleaseWhenTransientClientHasGuard(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('begin');
        $connection->expects($this->once())->method('rollback');

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        // Use public API to enter transaction state
        $transient = $client->getTransient();
        $transient->begin();

        // Should NOT call release() when guard exists (transient manages its own connection)
        $this->poolManager->expects($this->never())->method('release');
        // Act

        $transient->close();
        // Assert

        $this->assertFalse($transient->inTransaction());
    }

    public function testBeginNestedTransactionDoesNotCallBeginTwice(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('begin');

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        $transient->begin();
        $transient->begin();
        // Assert

        $this->assertTrue($transient->inTransaction());
    }

    public function testCommitNestedTransactionDoesNotCommitUntilLevelZero(): void
    {
        // Arrange
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('begin');
        $connection->expects($this->once())->method('commit');

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);
        // Act

        $transient = $client->getTransient();
        $transient->begin();
        $transient->begin();

        // First commit only decrements nesting level.
        $transient->commit();
        $this->assertTrue($transient->inTransaction());

        // Second commit actually commits.
        $transient->commit();

        // Assert
        $this->assertFalse($transient->inTransaction());
    }

    public function testCommitFailureStillClearsTransactionState(): void
    {
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('begin');
        $connection->expects($this->once())
            ->method('commit')
            ->willThrowException(new PDOException('commit failed'));

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        $transient->begin();

        $this->expectException(\Switon\Db\Exception::class);
        $this->expectExceptionMessage('Failed to commit database transaction');

        try {
            $transient->commit();
        } finally {
            $this->assertFalse($transient->inTransaction());
        }
    }

    public function testRollbackFailureStillClearsTransactionState(): void
    {
        $client = $this->createClientWithConfig(new PoolConfiguration(timeout: 1.0, prefix: '', hasSlave: false));

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('begin');
        $connection->expects($this->once())
            ->method('rollback')
            ->willThrowException(new PDOException('rollback failed'));

        $guard = new PoolGuard($this->poolManager, $client, $connection, 'default');
        $this->poolManager->method('guard')->willReturn($guard);

        $transient = $client->getTransient();
        $transient->begin();

        $this->expectException(\Switon\Db\Exception::class);
        $this->expectExceptionMessage('Failed to rollback database transaction');

        try {
            $transient->rollback();
        } finally {
            $this->assertFalse($transient->inTransaction());
        }
    }

    protected function createClientWithConfig(PoolConfiguration $config): ClientInterface
    {
        $this->poolConfigurator->method('configure')->willReturn($config);
        return $this->container->make(Client::class, ['uri' => 'mysql://localhost/test']);
    }
}
