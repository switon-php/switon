# Testing Database Code {#testing-database-code}

Patterns for code that depends on `Switon\Db\ClientInterface`: mock the client for fast unit tests, register a real
driver when SQL or constraints must be proven, and wrap integration cases in transactions when you want cheap
rollback-based cleanup on one pinned client.

**Key features:**

- **Mock-first unit tests** — swap `ClientInterface` in the test container and assert calls with PHPUnit expectations
- **Real-driver integration** — register `Switon\Di\Factory` with SQLite in-memory or a driver URI your suite owns
- **Transaction cleanup** — `getTransient()` + `begin()` / `rollback()` for fast rollback-based cleanup on one pinned
  client

> **Prerequisites:** [DB Package](README.md) · [Testing package](https://github.com/switon-php/testing/blob/main/docs/README.md)

See also: [DB Package — Quick Start](README.md#quick-start) · [Transient Connections](README.md#transient-connections)

## Which Approach? {#which-approach}

Pick the lightest approach that still proves what you care about.

- **Business logic and branching** — [Unit tests](#unit-tests) with mocks
- **SQL syntax, constraints, driver behavior** — [Integration tests](#integration-tests) with a real database
- **Many real-database cases with fast cleanup** — [Transaction cleanup](#transaction-isolation)

---

## Unit Tests (Mock) {#unit-tests}

Mock `ClientInterface` in the container, then create your service with `$this->make()`. The `#[Autowired]` properties
are injected automatically.

```php
use Switon\Testing\TestCase;
use Switon\Db\ClientInterface;

class UserServiceTest extends TestCase
{
    protected function setUpContainer(): void
    {
        $this->container->set(ClientInterface::class, $this->createMock(ClientInterface::class));
    }

    public function testFindActiveUsers(): void
    {
        // Arrange
        $db = $this->container->get(ClientInterface::class);
        $db->expects($this->once())
            ->method('fetchAll')
            ->with(
                'SELECT * FROM users WHERE status = :status',
                ['status' => 'active']
            )
            ->willReturn([
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ]);

        $service = $this->make(UserService::class);

        // Act
        $users = $service->findActive();

        // Assert
        $this->assertCount(2, $users);
        $this->assertSame('Alice', $users[0]['name']);
    }
}
```

Use this when you want fast feedback on service behavior. Do not use it to prove that SQL, indexes, or constraints are
correct.

---

## Integration Tests (Real Database) {#integration-tests}

Register a real database via `Switon\Di\Factory` in `setUpContainer()`. All services created with `$this->make()`
receive the real client.

### SQLite In-Memory {#sqlite}

This is the fastest real-database setup: no external service, instant schema creation, easy cleanup.

```php
use Switon\Testing\TestCase;
use Switon\Core\Attribute\Autowired;
use Switon\Db\ClientInterface;
use Switon\Di\Factory;

class UserRepositoryTest extends TestCase
{
    #[Autowired] protected ClientInterface $db;

    protected function setUpContainer(): void
    {
        (new Factory(['default' => ['uri' => 'sqlite::memory:']]))->register(
            ClientInterface::class,
            $this->container
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Test schema setup uses the generic write-side SQL entrypoint, which also accepts DDL.
        $this->db->executeUpdate('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                status TEXT DEFAULT "active"
            )
        ');
    }

    protected function tearDown(): void
    {
        $this->db->executeDelete('DELETE FROM users');
        parent::tearDown();
    }

    public function testCreateAndFind(): void
    {
        // Arrange
        $repo = $this->make(UserRepository::class);

        // Act
        $id = $repo->create(['name' => 'Alice', 'email' => 'alice@example.com']);

        // Assert
        $this->assertGreaterThan(0, $id);

        $rows = $this->db->fetchAll('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testDuplicateEmailThrows(): void
    {
        $this->db->insert('users', ['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->expectException(\Switon\Db\Exception::class);
        $this->db->insert('users', ['name' => 'Bob', 'email' => 'alice@example.com']);
    }
}
```

SQLite is excellent for repository tests, but it does not cover every MySQL/PostgreSQL behavior. When you need a
specific driver, point the test container at a real database URI:

```php
use Switon\Db\ClientInterface;
use Switon\Di\Factory;
use Switon\Testing\TestCase;

abstract class ExampleConfiguredDbTest extends TestCase
{
    protected function setUpContainer(): void
    {
        (new Factory([
            'default' => ['uri' => env('DB_TEST_URI', 'sqlite::memory:')],
        ]))->register(ClientInterface::class, $this->container);
    }
}
```

For PostgreSQL-specific tests, use `env('PG_TEST_URI', 'sqlite::memory:')`.

### Environment Variable Compatibility {#env-compatibility}

These names are accepted across the docs and package test suite:

| Purpose             | Preferred     | Also accepted                           |
|---------------------|---------------|-----------------------------------------|
| Generic DB test URI | `DB_TEST_URI` | `MYSQL_TEST_URI` for MySQL-focused runs |
| PostgreSQL test URI | `PG_TEST_URI` | `PGSQL_TEST_URI`                        |

Examples:

```bash
export DB_TEST_URI="mysql://root:password@localhost:3306/switon_test?charset=utf8mb4"
export PG_TEST_URI="pgsql://postgres:password@localhost:5432/switon_test"
```

---

## Transaction Cleanup {#transaction-isolation}

Wrap each test in a transaction and roll back after it finishes. This keeps cleanup cheap on the transient client that
owns the transaction.

```php
use Switon\Testing\TestCase;
use Switon\Core\Attribute\Autowired;
use Switon\Db\ClientInterface;
use Switon\Di\Factory;

abstract class DatabaseTestCase extends TestCase
{
    #[Autowired] protected ClientInterface $db;
    protected ClientInterface $tx;

    protected function setUpContainer(): void
    {
        (new Factory([
            'default' => ['uri' => env('DB_TEST_URI', 'sqlite::memory:')],
        ]))->register(ClientInterface::class, $this->container);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tx = $this->db->getTransient('default');
        $this->tx->begin();
    }

    protected function tearDown(): void
    {
        $this->tx->rollback();  // Safe if no transaction was started
        parent::tearDown();
    }
}
```

### Why two clients: `$this->db` vs `$this->tx` {#db-vs-tx}

`$this->db` is the normal DI-managed client. `$this->tx` is a transient pinned to one connection inside the transaction.
They are different connections, so uncommitted writes in `$this->tx` are not visible through `$this->db`.

This matters when testing services:

```php
class OrderServiceTest extends DatabaseTestCase
{
    public function testPlaceOrder(): void
    {
        // Insert fixture through tx
        $this->tx->insert('products', ['id' => 1, 'name' => 'Widget', 'price' => 10]);

        // Service gets DI client by default, so bind tx before make()
        $this->container->set(ClientInterface::class, $this->tx);
        $service = $this->make(OrderService::class);

        $orderId = $service->placeOrder(userId: 1, productId: 1);
        $this->assertGreaterThan(0, $orderId);

        // Verify through tx (same connection = sees uncommitted data)
        $rows = $this->tx->fetchAll('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        $this->assertCount(1, $rows);
    }
}
```

**Rule of thumb:** if fixtures are inserted through `$this->tx`, bind `ClientInterface` to `$this->tx` before
`$this->make(...)`.

This pattern is for isolation, not for testing commit/rollback itself. Use a separate non-wrapped test for transaction
semantics.

---

## Fixtures {#fixtures}

Insert known data through the transacted connection so the fixtures roll back with the test:

```php
class UserFixture
{
    public static function createDefaults(ClientInterface $db): void
    {
        $db->insert('users', ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $db->insert('users', ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'inactive']);
    }
}

class UserSearchTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        UserFixture::createDefaults($this->tx);
    }

    public function testFindActiveUsers(): void
    {
        $this->container->set(ClientInterface::class, $this->tx);
        $repo = $this->make(UserRepository::class);

        $users = $repo->findActive();

        $this->assertCount(1, $users);
        $this->assertSame('Alice', $users[0]['name']);
    }
}
```

---

## What to Assert {#what-to-assert}

- **Unit tests (mock)** — assert that the correct SQL and parameters were passed (`->with(...)`) and that the service
  returns/transforms the result correctly
- **Integration tests** — assert the **database state** after the operation: query back and check rows, counts, or
  column values
- **Error cases** — use `$this->expectException(...)` for constraint violations or `UnsafeOperationException`

Avoid asserting exact SQL strings in mocks when possible — they break when formatting changes. Assert parameters and
behavior instead.

---

## Common Pitfalls {#common-pitfalls}

Avoid these common mistakes when testing database code.

### Using production database for tests {#pitfall-prod-db}

Using production data makes tests flaky, slow, and dangerous.

> **Avoid:** Pointing tests at your production database.

Use a dedicated test database or `sqlite::memory:`. Configure with `DB_TEST_URI` / `PG_TEST_URI` / `MYSQL_TEST_URI`
depending on the driver under test.

### Bypassing the container {#pitfall-bypass-container}

If you construct services manually, `#[Autowired]` properties are not injected and behavior diverges from real runtime.

> **Avoid:** `new Service()` in tests.

Prefer `$this->make(Service::class)`. The container wires `#[Autowired]` automatically.

### Tests depend on execution order {#pitfall-order-dependent}

Order-dependent tests fail randomly and become hard to maintain.

> **Avoid:** Sharing state between tests.

Each test must create its own data. Use [Transaction isolation](#transaction-isolation) or `tearDown()` cleanup.

### Storing transient across tests {#pitfall-transient-leak}

Transient clients hold a pinned connection. Reusing them across tests can leak state and exhaust pools.

> **Avoid:** Keeping a transient client alive across multiple tests.

Create a new transient in `setUp()` and release it in `tearDown()` by rolling back.

### Mixing DI client and transacted client {#pitfall-db-tx}

When using transaction isolation, `$this->db` and `$this->tx` are different connections. Writes in `$this->tx` are not
visible via `$this->db` before commit.

> **Avoid:** Creating services with the default `ClientInterface` while fixtures were inserted through `$this->tx`.

Rebind `ClientInterface` to `$this->tx` in the container before `$this->make(...)`. See [db vs tx](#db-vs-tx).

---

## Next {#next}

- [DB Package](README.md) — clients, transactions, and safety rules
- [Testing package](https://github.com/switon-php/testing/blob/main/docs/README.md) — test harness and conventions
- [FAQ](faq.md#db-faq) — common questions and troubleshooting
- [Design Rationale](design.md#design-rationale) — why the DB boundaries are shaped this way
- [ORM Package](https://github.com/switon-php/orm/blob/main/docs/README.md) — repositories and entity persistence on top of DB
