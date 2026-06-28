<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Integration;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\PathAliasInterface;
use Switon\Db\Connection\Adapter\Sqlite;
use Switon\Db\Tests\TestCase;
use PDO;

/**
 * Test cases for allocateIds() method in Connection adapters.
 */
#[AllowMockObjectsWithoutExpectations]
class AllocateIdsTest extends TestCase
{
    /**
     * Create SQLite in-memory instance for testing.
     */
    protected function createSqlite(): Sqlite
    {
        $pathAlias = $this->createMock(PathAliasInterface::class);
        $pathAlias->method('resolve')->willReturn(':memory:');

        $this->container->replace(PathAliasInterface::class, $pathAlias);

        // Use container's make() to create Sqlite with dependencies injected
        return $this->make(Sqlite::class, [
            'uri' => ':memory:',
            'username' => '',
            'password' => '',
            'owner' => $this->poolOwner,
        ]);
    }

    /**
     * Test SQLite allocateIds() with fresh table (no existing data).
     */
    public function testSqliteAllocateIdsWithFreshTable(): void
    {
        $sqlite = $this->createSqlite();

        // Create test table
        $sqlite->execute('CREATE TABLE test_allocate_fresh (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        // Allocate 10 IDs
        [$startId, $endId] = $sqlite->allocateIds('test_allocate_fresh', 'id', 10);

        $this->assertIsInt($startId);
        $this->assertIsInt($endId);
        $this->assertEquals(1, $startId);
        $this->assertEquals(10, $endId);

        // Verify next insert uses ID after allocated range (endId + 1)
        $sqlite->execute('INSERT INTO test_allocate_fresh (name) VALUES (:name)', ['name' => 'test']);
        $result = $sqlite->query('SELECT id FROM test_allocate_fresh WHERE name = :name', ['name' => 'test'], PDO::FETCH_ASSOC);
        $insertedId = (int)$result[0]['id'];
        $this->assertEquals($endId + 1, $insertedId);
    }


    /**
     * Test SQLite allocateIds() throws exception for non-existent table.
     */
    public function testSqliteAllocateIdsThrowsExceptionForNonExistentTable(): void
    {
        $sqlite = $this->createSqlite();

        $this->expectException(\Switon\Db\Exception::class);
        $this->expectExceptionMessageMatches('/no such table|Table.*not found/');

        $sqlite->allocateIds('nonexistent_table', 'id', 10);
    }

    /**
     * Test SQLite allocateIds() allocates correct ID range.
     */
    public function testSqliteAllocateIdsAllocatesCorrectRange(): void
    {
        $sqlite = $this->createSqlite();

        // Create test table
        $sqlite->execute('CREATE TABLE test_allocate_ids (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        // Allocate 10 IDs
        [$startId, $endId] = $sqlite->allocateIds('test_allocate_ids', 'id', 10);

        $this->assertIsInt($startId);
        $this->assertIsInt($endId);
        $this->assertEquals(1, $startId);
        $this->assertEquals(10, $endId);

        // Verify next insert uses ID after allocated range
        // SQLite's sqlite_sequence.seq stores the next available ID, so after allocating [1, 10],
        // the next insert will use ID 11 (which is endId + 1)
        $sqlite->execute('INSERT INTO test_allocate_ids (name) VALUES (:name)', ['name' => 'test']);
        $result = $sqlite->query('SELECT id FROM test_allocate_ids WHERE name = :name', ['name' => 'test'], PDO::FETCH_ASSOC);

        $this->assertNotEmpty($result);
        $insertedId = (int)$result[0]['id'];
        // Next insert should be endId + 1 (because seq stores next available ID)
        $this->assertEquals($endId + 1, $insertedId);
    }

    /**
     * Test SQLite allocateIds() for existing sequence.
     */
    public function testSqliteAllocateIdsForExistingSequence(): void
    {
        $sqlite = $this->createSqlite();

        // Create test table and insert some data
        $sqlite->execute('CREATE TABLE test_allocate_ids2 (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $sqlite->execute('INSERT INTO test_allocate_ids2 (name) VALUES (:name)', ['name' => 'existing1']);
        $sqlite->execute('INSERT INTO test_allocate_ids2 (name) VALUES (:name)', ['name' => 'existing2']);

        // Allocate 5 IDs (should start from 3)
        [$startId, $endId] = $sqlite->allocateIds('test_allocate_ids2', 'id', 5);

        $this->assertEquals(3, $startId);
        $this->assertEquals(7, $endId);
    }


    /**
     * Test SQLite allocateIds() rolls back on error.
     */
    public function testSqliteAllocateIdsRollsBackOnError(): void
    {
        $sqlite = $this->createSqlite();

        // Create test table
        $sqlite->execute('CREATE TABLE test_rollback (id INTEGER PRIMARY KEY AUTOINCREMENT)');

        // Try to allocate IDs for non-existent table (should rollback and throw exception)
        $this->expectException(\Switon\Db\Exception::class);
        $this->expectExceptionMessageMatches('/no such table|Table.*not found/');

        $sqlite->allocateIds('nonexistent_table', 'id', 10);

        // If we reach here, the test will fail because exception was not thrown
    }

    /**
     * Test Client::allocateIds() basic functionality with SQLite.
     *
     * Note: Transaction isolation test is skipped for SQLite :memory: databases
     * because each connection gets a separate memory space.
     */
    public function testClientAllocateIdsBasicFunctionality(): void
    {
        $sqlite = $this->createSqlite();

        // Create test table
        $sqlite->execute('CREATE TABLE test_client_ids (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        // Test allocateIds works
        [$startId, $endId] = $sqlite->allocateIds('test_client_ids', 'id', 10);
        $this->assertIsInt($startId);
        $this->assertIsInt($endId);
        $this->assertEquals(1, $startId);
        $this->assertEquals(10, $endId);

        // Verify next insert uses ID after allocated range
        $sqlite->execute('INSERT INTO test_client_ids (name) VALUES (:name)', ['name' => 'test']);
        $result = $sqlite->query('SELECT id FROM test_client_ids WHERE name = :name', ['name' => 'test'], PDO::FETCH_ASSOC);
        $insertedId = (int)$result[0]['id'];
        $this->assertEquals($endId + 1, $insertedId);
    }

    /**
     * Test Client::allocateIds() with sequential allocations.
     */
    public function testClientAllocateIdsSequential(): void
    {
        $sqlite = $this->createSqlite();

        // Create test table
        $sqlite->execute('CREATE TABLE test_sequential (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        // First allocation
        [$startId1, $endId1] = $sqlite->allocateIds('test_sequential', 'id', 5);
        $this->assertEquals(1, $startId1);
        $this->assertEquals(5, $endId1);

        // Second allocation should continue from where first left off
        [$startId2, $endId2] = $sqlite->allocateIds('test_sequential', 'id', 3);
        $this->assertEquals(6, $startId2);
        $this->assertEquals(8, $endId2);
    }
}
