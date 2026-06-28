<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Integration;

use PDO;
use Switon\Db\Client;
use Switon\Db\Connection\Adapter\Pgsql;
use Switon\Db\Tests\TestCase;
use Exception;

/**
 * Integration tests for PostgreSQL adapter.
 *
 * Requires a running PostgreSQL server with switon_test database.
 * Skip these tests if PostgreSQL is not available.
 *
 * @group pgsql
 * @group integration
 */
class PgsqlIntegrationTest extends TestCase
{
    protected ?Pgsql $connection = null;
    protected static bool $skipTests = false;
    protected static string $skipReason = '';
    protected static array $pgsqlConfig = ['host' => 'localhost', 'user' => 'postgres', 'pass' => ''];
    protected static ?string $pgsqlUri = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $envUri = getenv('PG_TEST_URI') ?: getenv('PGSQL_TEST_URI') ?: null;
        if (is_string($envUri) && $envUri !== '') {
            if (self::canConnectWithUri($envUri)) {
                self::$pgsqlUri = $envUri;
                return;
            }

            self::$skipTests = true;
            self::$skipReason = 'PostgreSQL not available via PG_TEST_URI / PGSQL_TEST_URI.';
            return;
        }

        // Try common PostgreSQL configurations (Homebrew default: current user)
        $currentUser = getenv('USER') ?: getenv('USERNAME') ?: 'postgres';
        $configs = [
            ['host' => 'localhost', 'user' => $currentUser, 'pass' => ''],
            ['host' => 'localhost', 'user' => 'postgres', 'pass' => ''],
            ['host' => 'localhost', 'user' => 'postgres', 'pass' => 'postgres'],
        ];

        $connected = false;
        foreach ($configs as $config) {
            try {
                $pdo = new PDO(
                    "pgsql:host={$config['host']};dbname=switon_test;connect_timeout=1",
                    $config['user'],
                    $config['pass'],
                    [PDO::ATTR_TIMEOUT => 1]
                );
                $pdo = null;
                $connected = true;
                self::$pgsqlConfig = $config;
                break;
            } catch (Exception $e) {
                continue;
            }
        }

        if (!$connected) {
            self::$skipTests = true;
            self::$skipReason = 'PostgreSQL not available. Please create switon_test database.';
        }
    }

    protected static function canConnectWithUri(string $uri): bool
    {
        $parts = parse_url($uri);
        $scheme = $parts['scheme'] ?? null;
        if (!is_array($parts) || ($scheme !== 'pgsql' && $scheme !== 'postgres' && $scheme !== 'postgresql')) {
            return false;
        }

        $host = $parts['host'] ?? 'localhost';
        $port = isset($parts['port']) ? ';port=' . $parts['port'] : '';
        $db = trim($parts['path'] ?? '/switon_test', '/');
        if ($db === '') {
            $db = 'switon_test';
        }

        $dsn = "pgsql:host={$host}{$port};dbname={$db};connect_timeout=1";

        try {
            new PDO(
                $dsn,
                $parts['user'] ?? 'postgres',
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

        $uri = self::$pgsqlUri;
        if ($uri === null) {
            $config = self::$pgsqlConfig;
            $uri = "pgsql://{$config['user']}:{$config['pass']}@{$config['host']}/switon_test";
        }

        $this->container->set(Pgsql::class, ['uri' => $uri, 'owner' => $this->poolOwner]);
        $this->connection = $this->container->get(Pgsql::class);

        // Create test table
        $this->connection->execute('DROP TABLE IF EXISTS test_pgsql');
        $this->connection->execute('CREATE TABLE test_pgsql (id SERIAL PRIMARY KEY, name VARCHAR(100), status INT DEFAULT 0)');
    }

    protected function tearDown(): void
    {
        if ($this->connection) {
            try {
                $this->connection->execute('DROP TABLE IF EXISTS test_pgsql');
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
        $this->connection->execute("INSERT INTO test_pgsql (name, status) VALUES ('John', 1)");
        $this->connection->execute("INSERT INTO test_pgsql (name, status) VALUES ('Jane', 2)");

        $rows = $this->connection->query('SELECT * FROM test_pgsql ORDER BY id', [], PDO::FETCH_ASSOC);

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
            "INSERT INTO test_pgsql (name, status) VALUES (:name, :status)",
            ['name' => 'Test User', 'status' => 5]
        );

        $rows = $this->connection->query(
            'SELECT * FROM test_pgsql WHERE name = :name',
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
        $this->connection->execute("INSERT INTO test_pgsql (name, status) VALUES ('Original', 1)");

        $affected = $this->connection->execute(
            "UPDATE test_pgsql SET name = :name WHERE status = :status",
            ['name' => 'Updated', 'status' => 1]
        );

        $this->assertSame(1, $affected);

        $rows = $this->connection->query('SELECT name FROM test_pgsql', [], PDO::FETCH_ASSOC);
        $this->assertSame('Updated', $rows[0]['name']);
    }

    /**
     * Test DELETE operation.
     */
    public function testDelete(): void
    {
        $this->connection->execute("INSERT INTO test_pgsql (name) VALUES ('ToDelete')");
        $this->connection->execute("INSERT INTO test_pgsql (name) VALUES ('ToKeep')");

        $affected = $this->connection->execute(
            "DELETE FROM test_pgsql WHERE name = :name",
            ['name' => 'ToDelete']
        );

        $this->assertSame(1, $affected);

        $rows = $this->connection->query('SELECT * FROM test_pgsql', [], PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('ToKeep', $rows[0]['name']);
    }

    /**
     * Test tableExists returns true for existing table.
     */
    public function testTableExistsReturnsTrue(): void
    {
        $this->assertTrue($this->connection->tableExists('test_pgsql'));
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
        $this->assertContains('test_pgsql', $tables);
    }

    /**
     * Test getMetadata returns correct structure.
     */
    public function testGetMetadata(): void
    {
        $meta = $this->connection->getMetadata('test_pgsql');

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
     * Test PostgreSQL boolean columns are returned as PHP boolean type.
     *
     * This verifies that PDO returns native PHP boolean (true/false) for PostgreSQL
     * BOOLEAN columns, not string 't'/'f'. This justifies using strict comparison (===)
     * in Pgsql::getMetadata() when checking primary key status.
     *
     * Historical context: Old pg_* functions returned 't'/'f' strings, but PDO has
     * returned native boolean since ~2010.
     */
    public function testGetMetadataReturnsBooleanType(): void
    {
        // Query with boolean CASE expression (same as getMetadata implementation)
        $sql = "
            SELECT 
                c.column_name,
                CASE WHEN pk.column_name IS NOT NULL THEN true ELSE false END as is_primary
            FROM information_schema.columns c
            LEFT JOIN (
                SELECT kcu.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu 
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                WHERE tc.constraint_type = 'PRIMARY KEY'
                    AND tc.table_schema = :schema
                    AND tc.table_name = :table
            ) pk ON c.column_name = pk.column_name
            WHERE c.table_schema = :schema AND c.table_name = :table
            ORDER BY c.ordinal_position
        ";

        $fields = $this->connection->query($sql, ['schema' => 'public', 'table' => 'test_pgsql']);
        $this->assertNotEmpty($fields);

        // Find primary and non-primary columns
        $primaryColumn = null;
        $nonPrimaryColumn = null;

        foreach ($fields as $field) {
            if ($field['column_name'] === 'id') {
                $primaryColumn = $field;
            } elseif ($field['column_name'] === 'name') {
                $nonPrimaryColumn = $field;
            }
        }

        $this->assertNotNull($primaryColumn);
        $this->assertNotNull($nonPrimaryColumn);

        // Verify primary key returns boolean true (not 't', '1', or 1)
        $this->assertIsBool($primaryColumn['is_primary']);
        $this->assertSame(true, $primaryColumn['is_primary']);
        $this->assertNotSame('t', $primaryColumn['is_primary']);
        $this->assertNotSame(1, $primaryColumn['is_primary']);

        // Verify non-primary returns boolean false (not 'f', '0', or 0)
        $this->assertIsBool($nonPrimaryColumn['is_primary']);
        $this->assertSame(false, $nonPrimaryColumn['is_primary']);
        $this->assertNotSame('f', $nonPrimaryColumn['is_primary']);
        $this->assertNotSame(0, $nonPrimaryColumn['is_primary']);
    }

    /**
     * Test upsert inserts new record.
     */
    public function testUpsertInsertsNewRecord(): void
    {
        $affected = $this->connection->upsert(
            '"test_pgsql"',
            ['id' => 100, 'name' => 'New User', 'status' => 1],
            ['name', 'status'],
            'id'
        );

        $this->assertSame(1, $affected);

        $rows = $this->connection->query(
            'SELECT * FROM test_pgsql WHERE id = 100',
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
        $this->connection->execute("INSERT INTO test_pgsql (id, name, status) VALUES (100, 'Original', 1)");

        // Upsert should update
        $this->connection->upsert(
            '"test_pgsql"',
            ['id' => 100, 'name' => 'Updated', 'status' => 2],
            ['name', 'status'],
            'id'
        );

        $rows = $this->connection->query(
            'SELECT * FROM test_pgsql WHERE id = 100',
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
        $this->connection->execute("INSERT INTO test_pgsql (id, name, status) VALUES (100, 'Original', 1)");

        $this->connection->upsert(
            '"test_pgsql"',
            ['id' => 100, 'name' => 'Updated', 'status' => 2],
            ['name' => 'status = status + 1', 'status' => 2],
            'id'
        );

        $rows = $this->connection->query(
            'SELECT * FROM test_pgsql WHERE id = 100',
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
        $this->connection->execute("INSERT INTO test_pgsql (name) VALUES ('InTransaction')");
        $this->connection->commit();

        $rows = $this->connection->query('SELECT * FROM test_pgsql', [], PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
    }

    /**
     * Test transaction rollback.
     */
    public function testTransactionRollback(): void
    {
        $this->connection->begin();
        $this->connection->execute("INSERT INTO test_pgsql (name) VALUES ('ToRollback')");
        $this->connection->rollback();

        $rows = $this->connection->query('SELECT * FROM test_pgsql', [], PDO::FETCH_ASSOC);
        $this->assertCount(0, $rows);
    }

    /**
     * Test truncate clears table and resets sequence.
     */
    public function testTruncate(): void
    {
        $this->connection->execute("INSERT INTO test_pgsql (name) VALUES ('Test1')");
        $this->connection->execute("INSERT INTO test_pgsql (name) VALUES ('Test2')");

        $this->connection->truncate('test_pgsql');

        $rows = $this->connection->query('SELECT * FROM test_pgsql', [], PDO::FETCH_ASSOC);
        $this->assertCount(0, $rows);

        // Insert new record - ID should restart from 1
        $this->connection->execute("INSERT INTO test_pgsql (name) VALUES ('AfterTruncate')");
        $rows = $this->connection->query('SELECT id FROM test_pgsql', [], PDO::FETCH_ASSOC);
        $this->assertEquals(1, $rows[0]['id']);
    }

    /**
     * Test convertQuotes in actual query.
     */
    public function testConvertQuotesInQuery(): void
    {
        $this->connection->execute("INSERT INTO test_pgsql (name) VALUES ('Test')");

        // Use bracket notation - should be converted to double quotes
        $sql = $this->connection->convertQuotes('SELECT [name] FROM [test_pgsql]');
        $rows = $this->connection->query($sql, [], PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('Test', $rows[0]['name']);
    }

    /**
     * Test allocateIds reserves ID range.
     */
    public function testAllocateIds(): void
    {
        // Insert some initial data to set sequence
        $this->connection->execute("INSERT INTO test_pgsql (name) VALUES ('Initial')");

        [$startId, $endId] = $this->connection->allocateIds('test_pgsql', 'id', 10);

        $this->assertGreaterThan(1, $startId);
        $this->assertSame($startId + 9, $endId);

        // Next insert should use ID after allocated range
        $this->connection->execute("INSERT INTO test_pgsql (name) VALUES ('AfterAllocation')");
        $rows = $this->connection->query(
            'SELECT id FROM test_pgsql ORDER BY id DESC LIMIT 1',
            [],
            PDO::FETCH_ASSOC
        );

        $this->assertGreaterThan($endId, (int)$rows[0]['id']);
    }
}
