<?php

declare(strict_types=1);

namespace Switon\Orm\Tests;

use PDO;
use Switon\Db\ClientInterface;
use Switon\Di\Factory;

/**
 * Base test case for ORM integration tests with real database.
 *
 * Provides SQLite in-memory database for fast, isolated integration testing.
 * Each test gets a fresh database with automatic schema creation and cleanup.
 *
 * **Features:**
 * - SQLite :memory: database (fast, no disk IO)
 * - Automatic schema creation from SQL
 * - Automatic cleanup after each test
 * - Real database operations (no mocks)
 * - Simple data insertion helpers
 *
 * **Usage:**
 * <code>
 * class UserRepositoryIntegrationTest extends DatabaseTestCase
 * {
 *     protected function getSchema(): string
 *     {
 *         return "
 *             CREATE TABLE users (
 *                 id INTEGER PRIMARY KEY AUTOINCREMENT,
 *                 name TEXT NOT NULL,
 *                 email TEXT UNIQUE NOT NULL,
 *                 created_at TEXT
 *             );
 *         ";
 *     }
 *
 *     public function testFindUser()
 *     {
 *         // Insert test data
 *         $this->insert('users', ['name' => 'John', 'email' => 'john@example.com']);
 *
 *         // Test repository
 *         $user = $this->userRepository->findByEmail('john@example.com');
 *
 *         $this->assertEquals('John', $user->name);
 *     }
 * }
 * </code>
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected ClientInterface $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Create SQLite in-memory database
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Setup database connection in container
        $this->setupDatabase();

        // Create schema
        $schema = $this->getSchema();
        if (!empty($schema)) {
            $this->createSchema($schema);
        }
    }

    /**
     * Setup database connection in DI container.
     *
     * Configures a SQLite in-memory database connection and registers it
     * in the container for use by ORM components.
     */
    protected function setupDatabase(): void
    {
        // Skip if DB package is not available
        if (!interface_exists(ClientInterface::class, true)) {
            $this->markTestSkipped('DB package dependency not available');
        }

        // Register PDO instance
        $this->container->set(PDO::class, $this->pdo);

        // Register DB with Switon\Di\Factory (SQLite in-memory)
        $this->container->set(ClientInterface::class, new Factory([
            'default' => ['uri' => 'sqlite://test@:memory:'],
        ]));

        // Get the real Client from container
        $this->db = $this->container->get(ClientInterface::class);
    }

    /**
     * Get database schema SQL for test tables.
     *
     * Override this method to define your test database schema.
     * Use SQLite-compatible SQL syntax.
     *
     * **SQLite Notes:**
     * - Use INTEGER PRIMARY KEY AUTOINCREMENT for auto-increment IDs
     * - Use TEXT for strings (VARCHAR not needed)
     * - Use INTEGER for numbers
     * - Use REAL for decimals
     * - Use TEXT for dates (store as ISO 8601: '2024-01-19 10:30:00')
     *
     * @return string SQL statements to create tables (can be multiple statements)
     */
    abstract protected function getSchema(): string;

    /**
     * Create database schema from SQL.
     *
     * Executes SQL statements to create tables. Supports multiple statements
     * separated by semicolons.
     *
     * @param string $sql SQL statements to execute
     */
    protected function createSchema(string $sql): void
    {
        // Split by semicolon and execute each statement
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn ($stmt) => !empty($stmt)
        );

        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
    }

    /**
     * Insert test data into a table.
     *
     * Simple helper to insert test data without needing factories.
     * Returns the last inserted ID.
     *
     * **Example:**
     * <code>
     * $userId = $this->insert('users', [
     *     'name' => 'John Doe',
     *     'email' => 'john@example.com',
     *     'created_at' => date('Y-m-d H:i:s')
     * ]);
     * </code>
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs
     *
     * @return int Last inserted ID
     */
    protected function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn ($col) => ":$col", $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Query data from database.
     *
     * Simple helper to query test data.
     *
     * @param string $sql SQL query
     * @param array<string, mixed> $params Query parameters
     *
     * @return array<int, array<string, mixed>> Query results
     */
    protected function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count rows in a table.
     *
     * @param string $table Table name
     * @param array<string, mixed> $where WHERE conditions (column => value)
     *
     * @return int Row count
     */
    protected function countRows(string $table, array $where = []): int
    {
        $sql = "SELECT COUNT(*) FROM $table";

        if (!empty($where)) {
            $conditions = array_map(fn ($col) => "$col = :$col", array_keys($where));
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($where);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Assert that a table contains a row matching the given data.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Expected column => value pairs
     * @param string $message Optional assertion message
     */
    protected function assertDatabaseHas(string $table, array $data, string $message = ''): void
    {
        $count = $this->countRows($table, $data);

        $this->assertGreaterThan(
            0,
            $count,
            $message ?: sprintf(
                'Failed asserting that table "%s" contains row with data: %s',
                $table,
                json_encode($data)
            )
        );
    }

    /**
     * Assert that a table does not contain a row matching the given data.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs that should not exist
     * @param string $message Optional assertion message
     */
    protected function assertDatabaseMissing(string $table, array $data, string $message = ''): void
    {
        $count = $this->countRows($table, $data);

        $this->assertEquals(
            0,
            $count,
            $message ?: sprintf(
                'Failed asserting that table "%s" does not contain row with data: %s',
                $table,
                json_encode($data)
            )
        );
    }

    /**
     * Assert that a table has exactly the expected number of rows.
     *
     * @param string $table Table name
     * @param int $expected Expected row count
     * @param string $message Optional assertion message
     */
    protected function assertDatabaseCount(string $table, int $expected, string $message = ''): void
    {
        $actual = $this->countRows($table);

        $this->assertEquals(
            $expected,
            $actual,
            $message ?: sprintf(
                'Failed asserting that table "%s" has %d rows, found %d',
                $table,
                $expected,
                $actual
            )
        );
    }

    protected function tearDown(): void
    {
        // PDO connection will be automatically closed when $this->pdo is destroyed
        parent::tearDown();
    }
}
