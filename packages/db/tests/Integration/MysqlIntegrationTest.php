<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Integration;

use PDO;
use Switon\Db\Client;
use Switon\Db\Connection\Adapter\Mysql;
use Switon\Db\Tests\TestCase;
use Exception;

/**
 * Integration tests for MySQL adapter.
 *
 * Requires a running MySQL server with switon_test database.
 * Skip these tests if MySQL is not available.
 *
 * @group mysql
 * @group integration
 */
class MysqlIntegrationTest extends TestCase
{
    protected ?Mysql $connection = null;
    protected static bool $skipTests = false;
    protected static string $skipReason = '';
    protected static array $mysqlConfig = ['host' => '127.0.0.1', 'user' => 'root', 'pass' => ''];
    protected static ?string $mysqlUri = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $envUri = getenv('DB_TEST_URI') ?: getenv('MYSQL_TEST_URI') ?: null;
        if (is_string($envUri) && $envUri !== '') {
            if (self::canConnectWithUri($envUri)) {
                self::$mysqlUri = $envUri;
                return;
            }

            self::$skipTests = true;
            self::$skipReason = 'MySQL not available via DB_TEST_URI / MYSQL_TEST_URI.';
            return;
        }

        // Try common MySQL configurations
        $configs = [
            ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '123456'],
            ['host' => '127.0.0.1', 'user' => 'root', 'pass' => ''],
            ['host' => '127.0.0.1', 'user' => 'root', 'pass' => 'root'],
            ['host' => 'localhost', 'user' => 'root', 'pass' => ''],
        ];

        $connected = false;
        foreach ($configs as $config) {
            try {
                $pdo = new PDO(
                    "mysql:host={$config['host']};dbname=switon_test",
                    $config['user'],
                    $config['pass'],
                    [PDO::ATTR_TIMEOUT => 1]
                );
                $pdo = null;
                $connected = true;
                self::$mysqlConfig = $config;
                break;
            } catch (Exception $e) {
                continue;
            }
        }

        if (!$connected) {
            self::$skipTests = true;
            self::$skipReason = 'MySQL not available. Please create switon_test database.';
        }
    }

    protected static function canConnectWithUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'mysql') {
            return false;
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = isset($parts['port']) ? ';port=' . $parts['port'] : '';
        $db = trim($parts['path'] ?? '/switon_test', '/');
        if ($db === '') {
            $db = 'switon_test';
        }

        parse_str($parts['query'] ?? '', $query);
        $charset = isset($query['charset']) ? ';charset=' . $query['charset'] : '';
        $dsn = "mysql:host={$host}{$port};dbname={$db}{$charset}";

        try {
            new PDO(
                $dsn,
                $parts['user'] ?? 'root',
                $parts['pass'] ?? '',
                [PDO::ATTR_TIMEOUT => 1]
            );
            return true;
        } catch (Exception) {
            return false;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipTests) {
            $this->markTestSkipped(self::$skipReason);
        }

        $uri = self::$mysqlUri;
        if ($uri === null) {
            $config = self::$mysqlConfig;
            $uri = "mysql://{$config['user']}:{$config['pass']}@{$config['host']}/switon_test";
        }

        $this->container->set(Mysql::class, ['uri' => $uri]);
        $this->connection = $this->container->get(Mysql::class);

        // Create test table
        $this->connection->execute('DROP TABLE IF EXISTS test_mysql');
        $this->connection->execute('CREATE TABLE test_mysql (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), status INT DEFAULT 0) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        if ($this->connection) {
            try {
                $this->connection->execute('DROP TABLE IF EXISTS test_mysql');
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
            $this->connection->close();
        }

        parent::tearDown();
    }

    /**
     * Test basic INSERT and SELECT operations.
     */
    public function testInsertAndSelect(): void
    {
        $this->connection->execute("INSERT INTO test_mysql (name, status) VALUES ('John', 1)");
        $this->connection->execute("INSERT INTO test_mysql (name, status) VALUES ('Jane', 2)");

        $rows = $this->connection->query('SELECT * FROM test_mysql ORDER BY id', [], PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame('John', $rows[0]['name']);
        $this->assertSame('Jane', $rows[1]['name']);
    }

    /**
     * Test INSERT with parameter binding.
     */
    public function testInsertWithParameterBinding(): void
    {
        $this->connection->execute(
            "INSERT INTO test_mysql (name, status) VALUES (:name, :status)",
            ['name' => 'Test User', 'status' => 5]
        );

        $rows = $this->connection->query(
            'SELECT * FROM test_mysql WHERE name = :name',
            ['name' => 'Test User'],
            PDO::FETCH_ASSOC
        );

        $this->assertCount(1, $rows);
        $this->assertEquals(5, $rows[0]['status']);
    }

    /**
     * Test UPDATE operation.
     */
    public function testUpdate(): void
    {
        $this->connection->execute("INSERT INTO test_mysql (name, status) VALUES ('Original', 1)");

        $affected = $this->connection->execute(
            "UPDATE test_mysql SET name = :name WHERE status = :status",
            ['name' => 'Updated', 'status' => 1]
        );

        $this->assertSame(1, $affected);

        $rows = $this->connection->query('SELECT name FROM test_mysql', [], PDO::FETCH_ASSOC);
        $this->assertSame('Updated', $rows[0]['name']);
    }

    /**
     * Test DELETE operation.
     */
    public function testDelete(): void
    {
        $this->connection->execute("INSERT INTO test_mysql (name) VALUES ('ToDelete')");
        $this->connection->execute("INSERT INTO test_mysql (name) VALUES ('ToKeep')");

        $affected = $this->connection->execute(
            "DELETE FROM test_mysql WHERE name = :name",
            ['name' => 'ToDelete']
        );

        $this->assertSame(1, $affected);

        $rows = $this->connection->query('SELECT * FROM test_mysql', [], PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('ToKeep', $rows[0]['name']);
    }

    /**
     * Test tableExists returns true for existing table.
     */
    public function testTableExistsReturnsTrue(): void
    {
        $this->assertTrue($this->connection->tableExists('test_mysql'));
    }

    /**
     * Test tableExists returns false for non-existing table.
     */
    public function testTableExistsReturnsFalse(): void
    {
        $this->assertFalse($this->connection->tableExists('non_existing_table_xyz'));
    }

    /**
     * Test getTables returns table list.
     */
    public function testGetTables(): void
    {
        $tables = $this->connection->getTables();

        $this->assertIsArray($tables);
        $this->assertContains('test_mysql', $tables);
    }

    /**
     * Test getMetadata returns correct structure.
     */
    public function testGetMetadata(): void
    {
        $meta = $this->connection->getMetadata('test_mysql');

        // METADATA_ATTRIBUTES
        $this->assertArrayHasKey(Client::METADATA_ATTRIBUTES, $meta);
        $this->assertArrayHasKey('id', $meta[Client::METADATA_ATTRIBUTES]);
        $this->assertArrayHasKey('name', $meta[Client::METADATA_ATTRIBUTES]);
        $this->assertArrayHasKey('status', $meta[Client::METADATA_ATTRIBUTES]);

        // METADATA_PRIMARY_KEY
        $this->assertArrayHasKey(Client::METADATA_PRIMARY_KEY, $meta);
        $this->assertContains('id', $meta[Client::METADATA_PRIMARY_KEY]);

        // METADATA_AUTO_INCREMENT_KEY
        $this->assertArrayHasKey(Client::METADATA_AUTO_INCREMENT_KEY, $meta);
        $this->assertSame('id', $meta[Client::METADATA_AUTO_INCREMENT_KEY]);

        // METADATA_INT_TYPE_ATTRIBUTES
        $this->assertArrayHasKey(Client::METADATA_INT_TYPE_ATTRIBUTES, $meta);
        $this->assertContains('id', $meta[Client::METADATA_INT_TYPE_ATTRIBUTES]);
        $this->assertContains('status', $meta[Client::METADATA_INT_TYPE_ATTRIBUTES]);
    }

    /**
     * Test upsert inserts new record.
     */
    public function testUpsertInsertsNewRecord(): void
    {
        $affected = $this->connection->upsert(
            '`test_mysql`',
            ['id' => 100, 'name' => 'New User', 'status' => 1],
            ['name', 'status'],
            'id'
        );

        $this->assertGreaterThanOrEqual(1, $affected);

        $rows = $this->connection->query(
            'SELECT * FROM test_mysql WHERE id = 100',
            [],
            PDO::FETCH_ASSOC
        );
        $this->assertCount(1, $rows);
        $this->assertSame('New User', $rows[0]['name']);
    }

    /**
     * Test upsert updates existing record.
     */
    public function testUpsertUpdatesExistingRecord(): void
    {
        // First insert
        $this->connection->execute("INSERT INTO test_mysql (id, name, status) VALUES (100, 'Original', 1)");

        // Upsert should update (MySQL returns 2 for update via ON DUPLICATE KEY)
        $this->connection->upsert(
            '`test_mysql`',
            ['id' => 100, 'name' => 'Updated', 'status' => 2],
            ['name', 'status'],
            'id'
        );

        $rows = $this->connection->query(
            'SELECT * FROM test_mysql WHERE id = 100',
            [],
            PDO::FETCH_ASSOC
        );
        $this->assertCount(1, $rows);
        $this->assertSame('Updated', $rows[0]['name']);
        $this->assertEquals(2, $rows[0]['status']);
    }

    /**
     * Test upsert binds associative string update values instead of treating them as expressions.
     */
    public function testUpsertTreatsAssociativeStringUpdateValuesAsBoundValues(): void
    {
        $this->connection->execute("INSERT INTO test_mysql (id, name, status) VALUES (100, 'Original', 1)");

        $this->connection->upsert(
            '`test_mysql`',
            ['id' => 100, 'name' => 'Updated', 'status' => 2],
            ['name' => 'status = status + 1', 'status' => 2],
            'id'
        );

        $rows = $this->connection->query(
            'SELECT * FROM test_mysql WHERE id = 100',
            [],
            PDO::FETCH_ASSOC
        );
        $this->assertCount(1, $rows);
        $this->assertSame('status = status + 1', $rows[0]['name']);
        $this->assertEquals(2, $rows[0]['status']);
    }

    /**
     * Test transaction commit.
     */
    public function testTransactionCommit(): void
    {
        $this->connection->begin();
        $this->connection->execute("INSERT INTO test_mysql (name) VALUES ('InTransaction')");
        $this->connection->commit();

        $rows = $this->connection->query('SELECT * FROM test_mysql', [], PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
    }

    /**
     * Test transaction rollback.
     */
    public function testTransactionRollback(): void
    {
        $this->connection->begin();
        $this->connection->execute("INSERT INTO test_mysql (name) VALUES ('ToRollback')");
        $this->connection->rollback();

        $rows = $this->connection->query('SELECT * FROM test_mysql', [], PDO::FETCH_ASSOC);
        $this->assertCount(0, $rows);
    }

    /**
     * Test truncate clears table.
     */
    public function testTruncate(): void
    {
        $this->connection->execute("INSERT INTO test_mysql (name) VALUES ('Test1')");
        $this->connection->execute("INSERT INTO test_mysql (name) VALUES ('Test2')");

        $this->connection->truncate('test_mysql');

        $rows = $this->connection->query('SELECT * FROM test_mysql', [], PDO::FETCH_ASSOC);
        $this->assertCount(0, $rows);

        // Insert new record - ID should restart from 1
        $this->connection->execute("INSERT INTO test_mysql (name) VALUES ('AfterTruncate')");
        $rows = $this->connection->query('SELECT id FROM test_mysql', [], PDO::FETCH_ASSOC);
        $this->assertEquals(1, $rows[0]['id']);
    }

    /**
     * Test convertQuotes in actual query.
     */
    public function testConvertQuotesInQuery(): void
    {
        $this->connection->execute("INSERT INTO test_mysql (name) VALUES ('Test')");

        // Use bracket notation - should be converted to backticks
        $sql = $this->connection->convertQuotes('SELECT [name] FROM [test_mysql]');
        $rows = $this->connection->query($sql, [], PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('Test', $rows[0]['name']);
    }

    /**
     * Test allocateIds reserves ID range.
     */
    public function testAllocateIds(): void
    {
        // Insert some initial data to set auto_increment
        $this->connection->execute("INSERT INTO test_mysql (name) VALUES ('Initial')");

        [$startId, $endId] = $this->connection->allocateIds('test_mysql', 'id', 10);

        $this->assertGreaterThan(1, $startId);
        $this->assertSame($startId + 9, $endId);

        // Next insert should use ID after allocated range
        $this->connection->execute("INSERT INTO test_mysql (name) VALUES ('AfterAllocation')");
        $rows = $this->connection->query(
            'SELECT id FROM test_mysql ORDER BY id DESC LIMIT 1',
            [],
            PDO::FETCH_ASSOC
        );

        $this->assertGreaterThanOrEqual($endId + 1, (int)$rows[0]['id']);
    }
}
