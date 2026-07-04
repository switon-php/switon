<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Integration;

use Switon\Db\Connection\Adapter\Sqlite;
use Switon\Db\SqlFragmentInterface;
use Switon\Db\Tests\TestCase;

/**
 * Integration tests for SqlFragmentInterface with real SQLite database.
 *
 * Tests SQL fragment expressions directly against SQLite connection,
 * verifying that the generated SQL executes correctly.
 */
class SqlFragmentTest extends TestCase
{
    protected ?Sqlite $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container->set(Sqlite::class, ['uri' => ':memory:', 'owner' => $this->poolOwner]);
        $this->connection = $this->container->get(Sqlite::class);

        // Create test table
        $this->connection->execute('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                login_count INTEGER DEFAULT 0,
                balance INTEGER DEFAULT 0,
                status TEXT DEFAULT "active"
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
     * Test atomic increment expression executes correctly in SQLite.
     */
    public function testIncrementExpressionExecutesCorrectly(): void
    {
        // Insert initial record
        $this->connection->execute(
            'INSERT INTO users (name, login_count) VALUES (:name, :count)',
            ['name' => 'Alice', 'count' => 10]
        );

        // Create increment fragment and simulate Client behavior
        $fragment = $this->createIncrementFragment(1);
        $fragment->setField('login_count');

        // Build and execute SQL like Client::update() does
        $sql = 'UPDATE `users` SET ' . $fragment->getExpression() . ' WHERE `name` = :name';
        $bind = array_merge($fragment->getBind(), ['name' => 'Alice']);

        $affected = $this->connection->execute($sql, $bind);

        $this->assertEquals(1, $affected);

        // Verify the value was incremented
        $result = $this->connection->query('SELECT login_count FROM users WHERE name = :name', ['name' => 'Alice']);
        $this->assertEquals(11, $result[0]['login_count']);
    }

    /**
     * Test atomic decrement (negative increment) executes correctly.
     */
    public function testDecrementExpressionExecutesCorrectly(): void
    {
        $this->connection->execute(
            'INSERT INTO users (name, balance) VALUES (:name, :balance)',
            ['name' => 'Bob', 'balance' => 100]
        );

        $fragment = $this->createIncrementFragment(-30);
        $fragment->setField('balance');

        $sql = 'UPDATE `users` SET ' . $fragment->getExpression() . ' WHERE `name` = :name';
        $bind = array_merge($fragment->getBind(), ['name' => 'Bob']);

        $affected = $this->connection->execute($sql, $bind);

        $this->assertEquals(1, $affected);

        $result = $this->connection->query('SELECT balance FROM users WHERE name = :name', ['name' => 'Bob']);
        $this->assertEquals(70, $result[0]['balance']);
    }

    /**
     * Test mixing fragment with regular values in UPDATE.
     */
    public function testMixedFragmentAndRegularValuesExecuteCorrectly(): void
    {
        $this->connection->execute(
            'INSERT INTO users (name, login_count, status) VALUES (:name, :count, :status)',
            ['name' => 'Charlie', 'count' => 5, 'status' => 'inactive']
        );

        $fragment = $this->createIncrementFragment(1);
        $fragment->setField('login_count');

        // Mix fragment expression with regular field assignment
        $sql = 'UPDATE `users` SET ' . $fragment->getExpression() . ', `status` = :status WHERE `name` = :name';
        $bind = array_merge($fragment->getBind(), ['status' => 'active', 'name' => 'Charlie']);

        $affected = $this->connection->execute($sql, $bind);

        $this->assertEquals(1, $affected);

        $result = $this->connection->query('SELECT login_count, status FROM users WHERE name = :name', ['name' => 'Charlie']);
        $this->assertEquals(6, $result[0]['login_count']);
        $this->assertEquals('active', $result[0]['status']);
    }

    /**
     * Test multiple fragments in single UPDATE.
     */
    public function testMultipleFragmentsExecuteCorrectly(): void
    {
        $this->connection->execute(
            'INSERT INTO users (name, login_count, balance) VALUES (:name, :count, :balance)',
            ['name' => 'Dave', 'count' => 10, 'balance' => 200]
        );

        $incrementLogin = $this->createIncrementFragment(5);
        $incrementLogin->setField('login_count');

        $decrementBalance = $this->createIncrementFragment(-50);
        $decrementBalance->setField('balance');

        $sql = 'UPDATE `users` SET ' . $incrementLogin->getExpression() . ', ' . $decrementBalance->getExpression() . ' WHERE `name` = :name';
        $bind = array_merge($incrementLogin->getBind(), $decrementBalance->getBind(), ['name' => 'Dave']);

        $affected = $this->connection->execute($sql, $bind);

        $this->assertEquals(1, $affected);

        $result = $this->connection->query('SELECT login_count, balance FROM users WHERE name = :name', ['name' => 'Dave']);
        $this->assertEquals(15, $result[0]['login_count']);
        $this->assertEquals(150, $result[0]['balance']);
    }

    /**
     * Test fragment with empty bind array (raw SQL expression).
     */
    public function testFragmentWithEmptyBindExecutesCorrectly(): void
    {
        $this->connection->execute(
            'INSERT INTO users (name, login_count) VALUES (:name, :count)',
            ['name' => 'Eve', 'count' => 99]
        );

        // Raw SQL expression fragment (no bind parameters)
        $doubler = new class () implements SqlFragmentInterface {
            protected string $field = '';

            public function setField(string $field): static
            {
                $this->field = $field;
                return $this;
            }

            public function getExpression(): string
            {
                return "$this->field = $this->field * 2";
            }

            public function getBind(): array
            {
                return [];
            }
        };
        $doubler->setField('login_count');

        $sql = 'UPDATE `users` SET ' . $doubler->getExpression() . ' WHERE `name` = :name';
        $bind = array_merge($doubler->getBind(), ['name' => 'Eve']);

        $affected = $this->connection->execute($sql, $bind);

        $this->assertEquals(1, $affected);

        $result = $this->connection->query('SELECT login_count FROM users WHERE name = :name', ['name' => 'Eve']);
        $this->assertEquals(198, $result[0]['login_count']);
    }

    /**
     * Create an increment fragment for testing.
     */
    protected function createIncrementFragment(int $value): SqlFragmentInterface
    {
        return new class ($value) implements SqlFragmentInterface {
            protected string $field = '';

            public function __construct(protected int $value)
            {
            }

            public function setField(string $field): static
            {
                $this->field = $field;
                return $this;
            }

            public function getExpression(): string
            {
                return "$this->field = $this->field + :$this->field";
            }

            public function getBind(): array
            {
                return [$this->field => $this->value];
            }
        };
    }
}
