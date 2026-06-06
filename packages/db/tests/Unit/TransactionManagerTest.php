<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Switon\Core\ContextManagerInterface;
use Switon\Db\ClientInterface;
use Switon\Db\Exception\CrossDatabaseTransactionException;
use Switon\Db\Exception\NoActiveTransactionException;
use Switon\Db\Tests\TestCase;
use Switon\Db\TransactionManager;
use Switon\Di\NamedLookupInterface;
use RuntimeException;

/**
 * Test subclass that exposes protected methods for testing.
 */
class TestableTransactionManager extends TransactionManager
{
    /**
     * Expose protected isShardedConnectionName method for testing.
     */
    public function testIsShardedConnectionName(string $connectionName): bool
    {
        return $this->isShardedConnectionName($connectionName);
    }

    /**
     * Expose protected resetContext method for testing.
     */
    public function testResetContext(\Switon\Db\TransactionManagerContext $context): void
    {
        $this->resetContext($context);
    }
}

/**
 * Unit tests for TransactionManager.
 */
#[AllowMockObjectsWithoutExpectations]
class TransactionManagerTest extends TestCase
{
    protected TestableTransactionManager $transactionManager;
    protected MockObject|NamedLookupInterface $mockNamedLookup;
    protected MockObject|ContextManagerInterface $mockContextManager;
    protected MockObject|ClientInterface $mockDbClient;
    protected \Switon\Db\TransactionManagerContext $transactionContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockNamedLookup = $this->createMock(NamedLookupInterface::class);
        $this->mockContextManager = $this->createMock(ContextManagerInterface::class);
        $this->mockDbClient = $this->createMock(ClientInterface::class);
        $this->mockDbClient->method('getTransient')->willReturn($this->mockDbClient);

        $this->transactionManager = new TestableTransactionManager();
        $this->container->set(NamedLookupInterface::class, $this->mockNamedLookup);
        $this->container->set(ContextManagerInterface::class, $this->mockContextManager);
        $this->injector->inject($this->transactionManager);

        $this->transactionContext = new \Switon\Db\TransactionManagerContext();
        $this->mockContextManager->method('getContext')
            ->willReturn($this->transactionContext);
    }

    public function testBeginWithConnectionName(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'test_db')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())
            ->method('begin');

        // Act
        $this->transactionManager->begin('test_db');

        // Assert
        $this->assertTrue($this->transactionManager->useTransaction('test_db'));
        $this->assertTrue($this->transactionManager->isInTransaction('test_db'));
        $this->assertSame(['test_db'], $this->transactionManager->getActiveConnectionNames());
    }

    public function testBeginWithoutConnectionName(): void
    {
        // Act
        $this->transactionManager->begin(null);

        // Assert
        $this->assertTrue($this->transactionManager->useTransaction('test_db'));
        $this->assertFalse($this->transactionManager->isInTransaction('test_db'));
        $this->assertSame([], $this->transactionManager->getActiveConnectionNames());
    }

    public function testBeginThrowsExceptionWhenTransactionAlreadyStarted(): void
    {
        // Arrange
        $this->mockNamedLookup->method('by')->willReturn($this->mockDbClient);

        $this->transactionManager->begin('test_db');

        // Assert
        $this->expectException(CrossDatabaseTransactionException::class);
        $this->expectExceptionMessageMatches('/Transaction already active|nested transactions/i');

        // Act
        $this->transactionManager->begin('other_db');
    }

    public function testTransactionWithConnectionName(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'test_db')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())
            ->method('begin');

        $this->mockDbClient->expects($this->once())
            ->method('commit');

        // Act
        $result = $this->transactionManager->transaction(function () {
            return 'success';
        }, 'test_db');

        // Assert
        $this->assertSame('success', $result);
        $this->assertFalse($this->transactionManager->useTransaction('test_db'));
    }

    public function testTransactionWithoutConnectionName(): void
    {
        // Act
        $result = $this->transactionManager->transaction(function () {
            return 'success';
        }, null);

        // Assert
        $this->assertSame('success', $result);
        $this->assertFalse($this->transactionManager->useTransaction('test_db'));
    }

    public function testTransactionRollsBackOnException(): void
    {
        // Arrange
        $this->mockNamedLookup->method('by')->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())
            ->method('begin');

        $this->mockDbClient->expects($this->once())
            ->method('rollback');

        $this->mockDbClient->expects($this->never())
            ->method('commit');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        // Act
        $this->transactionManager->transaction(function () {
            throw new RuntimeException('Test exception');
        }, 'test_db');
    }

    public function testCommitThrowsExceptionWhenNoTransaction(): void
    {
        // Assert
        $this->expectException(NoActiveTransactionException::class);
        $this->expectExceptionMessageMatches('/No active transaction.*commit/');

        // Act
        $this->transactionManager->commit('test_db');
    }

    public function testCommitWithActiveTransaction(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'test_db')
            ->willReturn($this->mockDbClient);

        $this->transactionManager->begin('test_db');

        $this->mockDbClient->expects($this->once())
            ->method('commit');

        // Act
        $this->transactionManager->commit('test_db');

        // Assert
        $this->assertFalse($this->transactionManager->useTransaction('test_db'));
    }

    public function testRollbackThrowsExceptionWhenNoTransaction(): void
    {
        // Assert
        $this->expectException(NoActiveTransactionException::class);
        $this->expectExceptionMessageMatches('/No active transaction.*rollback/');

        // Act
        $this->transactionManager->rollback('test_db');
    }

    public function testRollbackWithActiveTransaction(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'test_db')
            ->willReturn($this->mockDbClient);

        $this->transactionManager->begin('test_db');

        $this->mockDbClient->expects($this->once())
            ->method('rollback');

        // Act
        $this->transactionManager->rollback('test_db');

        // Assert
        $this->assertFalse($this->transactionManager->useTransaction('test_db'));
    }

    public function testEnsureConnectionWithNoTransaction(): void
    {
        // Act
        $this->transactionManager->ensureConnection('test_db');

        // Assert
        $this->assertFalse($this->transactionManager->useTransaction('test_db'));
    }

    public function testEnsureConnectionWithDelayedStart(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'test_db')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())
            ->method('begin');

        $this->transactionManager->begin(null);

        // Act
        $this->transactionManager->ensureConnection('test_db');

        // Assert
        $this->assertTrue($this->transactionManager->isInTransaction('test_db'));
    }

    public function testEnsureConnectionThrowsWhenSecondLogicalConnectionIsRequested(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'db1')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())
            ->method('begin');

        $this->transactionManager->begin(null);
        $this->transactionManager->ensureConnection('db1');

        $this->expectException(CrossDatabaseTransactionException::class);
        $this->expectExceptionMessageMatches('/different logical connection|transaction scope bound/i');

        // Act
        $this->transactionManager->ensureConnection('db2');
    }

    public function testEnsureConnectionFailsFastWhenMultipleShardedConnectionsAreUsed(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'db1')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())
            ->method('begin');

        $this->transactionManager->begin(null);
        $this->transactionManager->ensureConnection('db1');

        $this->expectException(CrossDatabaseTransactionException::class);
        $this->expectExceptionMessageMatches('/different logical connection|transaction scope bound/i');

        // Act
        $this->transactionManager->ensureConnection('user:1');
    }

    public function testGetCurrentClientThrowsExceptionWhenNoTransaction(): void
    {
        // Assert
        $this->expectException(NoActiveTransactionException::class);
        $this->expectExceptionMessageMatches('/No active transaction/');

        // Act
        $this->transactionManager->getCurrentClient('test_db');
    }

    public function testGetCurrentClientThrowsExceptionWhenConnectionNotDetermined(): void
    {
        // Arrange
        $this->transactionManager->begin(null);

        // Assert
        $this->expectException(NoActiveTransactionException::class);
        $this->expectExceptionMessageMatches('/No connection established|no connection determined/i');

        // Act
        $this->transactionManager->getCurrentClient('test_db');
    }

    public function testGetCurrentClientReturnsClientForActiveTransaction(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'test_db')
            ->willReturn($this->mockDbClient);

        $this->transactionManager->begin('test_db');

        // Act
        $client = $this->transactionManager->getCurrentClient('test_db');

        // Assert
        $this->assertSame($this->mockDbClient, $client);
    }

    public function testUseTransactionReturnsFalseWhenNoTransaction(): void
    {
        // Act & Assert
        $this->assertFalse($this->transactionManager->useTransaction('test_db'));
    }

    public function testUseTransactionReturnsTrueWhenTransactionStarted(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'test_db')
            ->willReturn($this->mockDbClient);

        $this->transactionManager->begin('test_db');

        // Act & Assert
        $this->assertTrue($this->transactionManager->useTransaction('test_db'));
    }

    public function testUseTransactionReturnsTrueWhenTransactionDelayed(): void
    {
        // Arrange
        $this->transactionManager->begin(null);

        // Act & Assert
        $this->assertTrue($this->transactionManager->useTransaction('test_db'));
        $this->assertFalse($this->transactionManager->isInTransaction('test_db'));
    }

    public function testUseTransactionThrowsWhenActiveConnectionsExistButConnectionNotIncluded(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'db1')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())
            ->method('begin');

        $this->transactionManager->begin(null);
        $this->transactionManager->ensureConnection('db1');

        // Act & Assert
        $this->assertTrue($this->transactionManager->useTransaction('db1'));
        $this->expectException(CrossDatabaseTransactionException::class);
        $this->expectExceptionMessageMatches('/different logical connection|transaction scope bound/i');
        $this->transactionManager->useTransaction('db2');
    }

    public function testEnsureConnectionThrowsWhenDifferentLogicalConnectionRequestedAfterBinding(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'db1')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())
            ->method('begin');

        $this->transactionManager->begin(null);
        $this->transactionManager->ensureConnection('db1');

        // Assert
        $this->expectException(CrossDatabaseTransactionException::class);
        $this->expectExceptionMessageMatches('/different logical connection|transaction scope bound/i');

        // Act
        $this->transactionManager->ensureConnection('db2');
    }

    public function testEnsureConnectionTracksShardedTransactionAndDoesNotBeginTwiceForSameShard(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'user:1')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())
            ->method('begin');

        $this->transactionManager->begin(null);

        // Act
        $this->transactionManager->ensureConnection('user:1');
        $this->transactionManager->ensureConnection('user:1');

        // Assert
        $this->assertTrue($this->transactionContext->hasShardedTransaction);
        $this->assertSame(['user:1'], $this->transactionManager->getActiveConnectionNames());
        $this->assertArrayHasKey('user:1', $this->transactionContext->shardedConnections);
    }

    public function testCommitClearsShardedTransactionFlagWhenLastShardCommitted(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'user:1')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())
            ->method('begin');

        $this->mockDbClient->expects($this->once())
            ->method('commit');

        $this->transactionManager->begin(null);
        $this->transactionManager->ensureConnection('user:1');

        $this->assertTrue($this->transactionContext->hasShardedTransaction);

        // Act
        $this->transactionManager->commit('user:1');

        // Assert
        $this->assertFalse($this->transactionContext->hasShardedTransaction);
        $this->assertFalse($this->transactionManager->useTransaction('user:1'));
        $this->assertSame([], $this->transactionManager->getActiveConnectionNames());
    }

    public function testTransactionResetsContextWhenCallbackThrowsBeforeAnyConnectionDetermined(): void
    {
        // Arrange & Act
        try {
            $this->transactionManager->transaction(function () {
                throw new RuntimeException('boom');
            }, null);
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // Assert
        $this->assertFalse($this->transactionManager->useTransaction('test_db'));
        $this->assertSame([], $this->transactionManager->getActiveConnectionNames());
    }

    public function testIsShardedConnectionNameDetectsSeparators(): void
    {
        // Act & Assert - use testable manager's exposed method
        $this->assertFalse($this->transactionManager->testIsShardedConnectionName('db'));
        $this->assertFalse($this->transactionManager->testIsShardedConnectionName('db1'));
        $this->assertTrue($this->transactionManager->testIsShardedConnectionName('user:1'));
        $this->assertTrue($this->transactionManager->testIsShardedConnectionName('user,1'));
    }

    public function testResetContextClearsAllFlagsAndCollections(): void
    {
        // Arrange
        $context = new \Switon\Db\TransactionManagerContext();
        $context->useTransaction = true;
        $context->connections = ['db1' => true];
        $context->clients = ['db1' => $this->mockDbClient];
        $context->shardedConnections = ['user:1' => true];
        $context->hasShardedTransaction = true;

        // Act - use testable manager's exposed method
        $this->transactionManager->testResetContext($context);

        // Assert
        $this->assertFalse($context->useTransaction);
        $this->assertSame([], $context->connections);
        $this->assertSame([], $context->clients);
        $this->assertSame([], $context->shardedConnections);
        $this->assertFalse($context->hasShardedTransaction);
    }

    public function testCommitThrowsWhenTransactionActiveButClientMissing(): void
    {
        // Arrange
        $this->transactionManager->begin(null);

        // Assert
        $this->expectException(NoActiveTransactionException::class);

        // Act
        $this->transactionManager->commit('db1');
    }

    public function testRollbackThrowsWhenTransactionActiveButClientMissing(): void
    {
        // Arrange
        $this->transactionManager->begin(null);

        // Assert
        $this->expectException(NoActiveTransactionException::class);

        // Act
        $this->transactionManager->rollback('db1');
    }

    public function testCommitAllThrowsWhenNoTransaction(): void
    {
        // Assert
        $this->expectException(NoActiveTransactionException::class);

        // Act
        $this->transactionManager->commitAll();
    }

    public function testRollbackAllThrowsWhenNoTransaction(): void
    {
        // Assert
        $this->expectException(NoActiveTransactionException::class);

        // Act
        $this->transactionManager->rollbackAll();
    }

    public function testTransactionWithDelayedConnectionCommitsAllOnSuccess(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'db1')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())->method('begin');
        $this->mockDbClient->expects($this->once())->method('commit');

        // Act
        $result = $this->transactionManager->transaction(function () {
            $this->transactionManager->ensureConnection('db1');
            return 'ok';
        }, null);

        // Assert
        $this->assertSame('ok', $result);
        $this->assertFalse($this->transactionManager->useTransaction('db1'));
    }

    public function testTransactionWithDelayedConnectionRollsBackAllOnException(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'db1')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())->method('begin');
        $this->mockDbClient->expects($this->once())->method('rollback');

        $this->expectException(RuntimeException::class);

        // Act
        $this->transactionManager->transaction(function () {
            $this->transactionManager->ensureConnection('db1');
            throw new RuntimeException('fail');
        }, null);
    }

    public function testCommitAllCommitsSingleActiveConnection(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'db1')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())
            ->method('begin');

        $this->mockDbClient->expects($this->once())
            ->method('commit');

        $this->transactionManager->begin(null);
        $this->transactionManager->ensureConnection('db1');

        // Act
        $this->transactionManager->commitAll();

        // Assert
        $this->assertFalse($this->transactionManager->useTransaction('db1'));
    }

    public function testRollbackAllRollsBackSingleActiveConnection(): void
    {
        // Arrange
        $this->mockNamedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'db1')
            ->willReturn($this->mockDbClient);

        $this->mockDbClient->expects($this->once())
            ->method('begin');

        $this->mockDbClient->expects($this->once())
            ->method('rollback');

        $this->transactionManager->begin(null);
        $this->transactionManager->ensureConnection('db1');

        // Act
        $this->transactionManager->rollbackAll();

        // Assert
        $this->assertFalse($this->transactionManager->useTransaction('db1'));
    }
}
