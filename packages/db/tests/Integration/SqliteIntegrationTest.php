<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Integration;

use Switon\Db\Client;
use Switon\Db\ClientInterface;
use Switon\Db\Connection\Adapter\Sqlite;
use Switon\Db\Tests\TestCase;
use Switon\Di\Factory;

/**
 * Integration tests for SQLite adapter.
 *
 * Uses in-memory SQLite database, always available.
 *
 * @group sqlite
 * @group integration
 */
class SqliteIntegrationTest extends TestCase
{
    protected ?Sqlite $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container->set(Sqlite::class, ['uri' => ':memory:']);
        $this->connection = $this->container->get(Sqlite::class);

        // Create test table
        $this->connection->execute('
            CREATE TABLE test_sqlite (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE,
                age INTEGER DEFAULT 0,
                created_at TEXT
            )
        ');
    }

    protected function tearDown(): void
    {
        if ($this->connection) {
            $this->connection->close();
        }
        parent::tearDown();
    }

    /**
     * Test basic INSERT and SELECT operations.
     */
    public function testInsertAndSelect(): void
    {
        $this->connection->execute(
            'INSERT INTO test_sqlite (name, email, age) VALUES (:name, :email, :age)',
            ['name' => 'Alice', 'email' => 'alice@test.com', 'age' => 25]
        );

        $result = $this->connection->query('SELECT * FROM test_sqlite WHERE name = :name', ['name' => 'Alice']);

        $this->assertCount(1, $result);
        $this->assertSame('Alice', $result[0]['name']);
        $this->assertSame('alice@test.com', $result[0]['email']);
        // SQLite returns strings for integers
        $this->assertEquals(25, $result[0]['age']);
    }

    /**
     * Test INSERT returns affected rows (execute without has_insert_id flag).
     */
    public function testInsertReturnsAffectedRows(): void
    {
        $affected1 = $this->connection->execute(
            'INSERT INTO test_sqlite (name, email) VALUES (:name, :email)',
            ['name' => 'User1', 'email' => 'user1@test.com']
        );

        $affected2 = $this->connection->execute(
            'INSERT INTO test_sqlite (name, email) VALUES (:name, :email)',
            ['name' => 'User2', 'email' => 'user2@test.com']
        );

        // execute() returns rowCount, not lastInsertId (unless has_insert_id=true)
        $this->assertEquals(1, $affected1);
        $this->assertEquals(1, $affected2);

        // Verify records were inserted with correct IDs
        $rows = $this->connection->query('SELECT id, name FROM test_sqlite ORDER BY id');
        $this->assertCount(2, $rows);
        $this->assertEquals(1, $rows[0]['id']);
        $this->assertEquals(2, $rows[1]['id']);
    }

    /**
     * Test UPDATE returns affected rows.
     */
    public function testUpdateReturnsAffectedRows(): void
    {
        $this->connection->execute('INSERT INTO test_sqlite (name, email, age) VALUES (:name, :email, :age)', ['name' => 'A', 'email' => 'a@test.com', 'age' => 20]);
        $this->connection->execute('INSERT INTO test_sqlite (name, email, age) VALUES (:name, :email, :age)', ['name' => 'B', 'email' => 'b@test.com', 'age' => 20]);
        $this->connection->execute('INSERT INTO test_sqlite (name, email, age) VALUES (:name, :email, :age)', ['name' => 'C', 'email' => 'c@test.com', 'age' => 30]);

        $affected = $this->connection->execute('UPDATE test_sqlite SET age = :new_age WHERE age = :old_age', ['new_age' => 21, 'old_age' => 20]);

        $this->assertEquals(2, $affected);
    }

    /**
     * Test DELETE returns affected rows.
     */
    public function testDeleteReturnsAffectedRows(): void
    {
        $this->connection->execute('INSERT INTO test_sqlite (name, email) VALUES (:name, :email)', ['name' => 'A', 'email' => 'a@test.com']);
        $this->connection->execute('INSERT INTO test_sqlite (name, email) VALUES (:name, :email)', ['name' => 'B', 'email' => 'b@test.com']);

        $affected = $this->connection->execute('DELETE FROM test_sqlite WHERE name = :name', ['name' => 'A']);

        $this->assertEquals(1, $affected);

        $result = $this->connection->query('SELECT * FROM test_sqlite');
        $this->assertCount(1, $result);
    }

    /**
     * Test transaction commit.
     */
    public function testTransactionCommit(): void
    {
        $this->connection->begin();
        $this->connection->execute('INSERT INTO test_sqlite (name, email) VALUES (:name, :email)', ['name' => 'TxUser', 'email' => 'tx@test.com']);
        $this->connection->commit();

        $result = $this->connection->query('SELECT * FROM test_sqlite WHERE name = :name', ['name' => 'TxUser']);
        $this->assertCount(1, $result);
    }

    /**
     * Test transaction rollback.
     */
    public function testTransactionRollback(): void
    {
        $this->connection->execute('INSERT INTO test_sqlite (name, email) VALUES (:name, :email)', ['name' => 'Before', 'email' => 'before@test.com']);

        $this->connection->begin();
        $this->connection->execute('INSERT INTO test_sqlite (name, email) VALUES (:name, :email)', ['name' => 'During', 'email' => 'during@test.com']);
        $this->connection->rollback();

        $result = $this->connection->query('SELECT * FROM test_sqlite');
        $this->assertCount(1, $result);
        $this->assertSame('Before', $result[0]['name']);
    }

    /**
     * Test getMetadata returns correct structure.
     */
    public function testGetMetadata(): void
    {
        $metadata = $this->connection->getMetadata('test_sqlite');

        // Keys are integer constants from Client class
        $this->assertArrayHasKey(Client::METADATA_ATTRIBUTES, $metadata); // 0
        $this->assertArrayHasKey(Client::METADATA_PRIMARY_KEY, $metadata); // 1
        $this->assertArrayHasKey(Client::METADATA_AUTO_INCREMENT_KEY, $metadata); // 2
        $this->assertArrayHasKey(Client::METADATA_INT_TYPE_ATTRIBUTES, $metadata); // 3

        // Check columns exist
        $this->assertArrayHasKey('id', $metadata[0]);
        $this->assertArrayHasKey('name', $metadata[0]);
        $this->assertArrayHasKey('email', $metadata[0]);
        $this->assertArrayHasKey('age', $metadata[0]);

        // Check auto increment
        $this->assertSame('id', $metadata[Client::METADATA_AUTO_INCREMENT_KEY]);

        // Check primary key
        $this->assertContains('id', $metadata[1]);
    }

    /**
     * Test getTables returns table list.
     */
    public function testGetTables(): void
    {
        // Create another table
        $this->connection->execute('CREATE TABLE another_table (id INTEGER PRIMARY KEY)');

        $tables = $this->connection->getTables();

        $this->assertContains('test_sqlite', $tables);
        $this->assertContains('another_table', $tables);
    }

    /**
     * Test tableExists returns true for existing table.
     */
    public function testTableExistsReturnsTrue(): void
    {
        $this->assertTrue($this->connection->tableExists('test_sqlite'));
    }

    /**
     * Test tableExists returns false for non-existing table.
     */
    public function testTableExistsReturnsFalse(): void
    {
        $this->assertFalse($this->connection->tableExists('non_existent_table'));
    }

    /**
     * Test upsert INSERT (new record).
     */
    public function testUpsertInsert(): void
    {
        $affected = $this->connection->upsert(
            'test_sqlite',
            ['name' => 'UpsertUser', 'email' => 'upsert@test.com', 'age' => 30],
            ['name', 'age'],
            'email'
        );

        $this->assertEquals(1, $affected);

        $result = $this->connection->query('SELECT * FROM test_sqlite WHERE email = :email', ['email' => 'upsert@test.com']);
        $this->assertCount(1, $result);
        $this->assertSame('UpsertUser', $result[0]['name']);
    }

    /**
     * Test upsert UPDATE (existing record).
     */
    public function testUpsertUpdate(): void
    {
        // Insert first
        $this->connection->execute(
            'INSERT INTO test_sqlite (name, email, age) VALUES (:name, :email, :age)',
            ['name' => 'Original', 'email' => 'upsert@test.com', 'age' => 20]
        );

        // Upsert should update
        $affected = $this->connection->upsert(
            'test_sqlite',
            ['name' => 'Updated', 'email' => 'upsert@test.com', 'age' => 30],
            ['name', 'age'],
            'email'
        );

        $this->assertEquals(1, $affected);

        $result = $this->connection->query('SELECT * FROM test_sqlite WHERE email = :email', ['email' => 'upsert@test.com']);
        $this->assertCount(1, $result);
        $this->assertSame('Updated', $result[0]['name']);
        $this->assertEquals(30, $result[0]['age']);
    }

    public function testUpsertTreatsAssociativeStringUpdateValuesAsBoundValues(): void
    {
        $this->connection->execute(
            'INSERT INTO test_sqlite (name, email, age) VALUES (:name, :email, :age)',
            ['name' => 'Original', 'email' => 'assoc@test.com', 'age' => 20]
        );

        $affected = $this->connection->upsert(
            'test_sqlite',
            ['name' => 'Updated', 'email' => 'assoc@test.com', 'age' => 30],
            ['name' => 'name || "!"', 'age' => 30],
            'email'
        );

        $this->assertEquals(1, $affected);

        $result = $this->connection->query('SELECT * FROM test_sqlite WHERE email = :email', ['email' => 'assoc@test.com']);
        $this->assertCount(1, $result);
        $this->assertSame('name || "!"', $result[0]['name']);
        $this->assertEquals(30, $result[0]['age']);
    }

    /**
     * Test allocateIds reserves ID range.
     */
    public function testAllocateIds(): void
    {
        // Insert some records first
        $this->connection->execute('INSERT INTO test_sqlite (name, email) VALUES (:name, :email)', ['name' => 'A', 'email' => 'a@test.com']);
        $this->connection->execute('INSERT INTO test_sqlite (name, email) VALUES (:name, :email)', ['name' => 'B', 'email' => 'b@test.com']);

        // Current max ID is 2, allocating 3 should reserve 3, 4, 5
        [$startId, $endId] = $this->connection->allocateIds('test_sqlite', 'id', 3);

        $this->assertEquals(3, $startId);
        $this->assertEquals(5, $endId);

        // Next insert should get ID 6 (verify via query since execute returns rowCount)
        $this->connection->execute('INSERT INTO test_sqlite (name, email) VALUES (:name, :email)', ['name' => 'C', 'email' => 'c@test.com']);
        $rows = $this->connection->query('SELECT id FROM test_sqlite WHERE name = :name', ['name' => 'C']);
        $this->assertEquals(6, $rows[0]['id']);
    }

    /**
     * Test truncate clears table and resets auto increment.
     */
    public function testTruncate(): void
    {
        $this->connection->execute('INSERT INTO test_sqlite (name, email) VALUES (:name, :email)', ['name' => 'A', 'email' => 'a@test.com']);
        $this->connection->execute('INSERT INTO test_sqlite (name, email) VALUES (:name, :email)', ['name' => 'B', 'email' => 'b@test.com']);

        $this->connection->truncate('test_sqlite');

        $result = $this->connection->query('SELECT * FROM test_sqlite');
        $this->assertCount(0, $result);

        // Auto-increment should reset - verify via query
        $this->connection->execute('INSERT INTO test_sqlite (name, email) VALUES (:name, :email)', ['name' => 'C', 'email' => 'c@test.com']);
        $rows = $this->connection->query('SELECT id FROM test_sqlite WHERE name = :name', ['name' => 'C']);
        $this->assertEquals(1, $rows[0]['id']);
    }

    /**
     * Documented URI sqlite::memory: must not create a sqlite::memory: file in the working directory.
     */
    public function testSqliteMemoryUriDoesNotCreateDiskFile(): void
    {
        $cwd = getcwd();
        $dir = sys_get_temp_dir() . '/switon-sqlite-memory-uri-' . uniqid('', true);
        mkdir($dir, 0777, true);

        try {
            chdir($dir);

            $this->container->set(ClientInterface::class, new Factory([
                'default' => ['uri' => 'sqlite::memory:'],
            ]));

            $client = $this->container->get(ClientInterface::class);
            $client->executeUpdate('SELECT 1');

            $this->assertFileDoesNotExist($dir . '/sqlite::memory:');
        } finally {
            chdir($cwd);
            rmdir($dir);
        }
    }

    /**
     * Test convertQuotes replaces bracket notation.
     */
    public function testConvertQuotes(): void
    {
        // SQLite uses backticks for identifiers
        $sql = $this->connection->convertQuotes('SELECT [name], [email] FROM [test_sqlite] WHERE [age] > 0');

        $this->assertSame('SELECT `name`, `email` FROM `test_sqlite` WHERE `age` > 0', $sql);

        // Should work with actual query
        $this->connection->execute('INSERT INTO test_sqlite (name, email, age) VALUES (:name, :email, :age)', ['name' => 'Test', 'email' => 'test@test.com', 'age' => 25]);
        $result = $this->connection->query($sql);
        $this->assertCount(1, $result);
    }
}
