---
sidebar_label: 数据库测试
description: 依赖 ClientInterface 的代码如何 mock、接真库、以及用事务回滚做快速清理。
---

# 测试数据库相关代码 {#testing-database-code}

依赖 `Switon\Db\ClientInterface` 时的常见套路：**单测优先 mock** client；必须验证 SQL / 约束 / 驱动行为时再挂**真实驱动**；需要在同一颗固定瞬连上廉价清理时，用**事务包裹集成用例并 rollback**。

**要点：**

- **Mock 先行单测** — 在测试容器里换掉 `ClientInterface`，用 PHPUnit 期望断言调用
- **真库集成** — `setUpContainer()` 里注册带 SQLite 内存库或自有测试 URI 的 `Switon\Di\Factory`
- **事务清理** — `getTransient()` + `begin()` / `rollback()`，在单一固定瞬连上做基于回滚的快速清理

> **阅读前提：** [DB 包](README.md) · [Testing 包](../testing/README.md)

参见：[DB 包 — 快速开始](README.md#quick-start) · [瞬连](README.md#transient-connections)

## 选哪种方式？ {#which-approach}

选**最轻、仍能证明你在乎的点**的那一档。

- **业务分支与逻辑** — 带 mock 的 [单元测试](#unit-tests)
- **SQL 语法、约束、驱动行为** — 带真实库的 [集成测试](#integration-tests)
- **大量真库用例又要清理便宜** — [事务清理](#transaction-isolation)

---

## 单元测试（Mock） {#unit-tests}

在容器里 mock `ClientInterface`，再用 `$this->make()` 创建服务，`#[Autowired]` 会自动注入。

```php
use Switon\Testing\TestCase;
use Switon\Db\ClientInterface;

class UserServiceTest extends TestCase
{
    protected ClientInterface $dbMock;

    protected function setUpContainer(): void
    {
        $this->dbMock = $this->createMock(ClientInterface::class);
        $this->container->set(ClientInterface::class, $this->dbMock);
    }

    public function testFindActiveUsers(): void
    {
        // Arrange
        $this->dbMock->expects($this->once())
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

适合快速验证服务行为。**不要**指望用它证明 SQL、索引或约束正确。

---

## 集成测试（真实数据库） {#integration-tests}

在 `setUpContainer()` 里用 `Switon\Di\Factory` 注册真实库。之后 `$this->make()` 拿到的服务都注入真实 client。

### SQLite 内存 {#sqlite}

最快的真实库方案：无外置服务、建表快、清理简单。

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

        // 建表走通用写入口，也接受 DDL。
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

SQLite 很适合仓储测试，但**不能**覆盖所有 MySQL/PostgreSQL 行为。需要特定驱动时，把测试容器指向真实 URI：

```php
protected function setUpContainer(): void
{
    (new Factory([
        'default' => ['uri' => env('DB_TEST_URI', 'sqlite::memory:')],
    ]))->register(ClientInterface::class, $this->container);
}
```

PostgreSQL 专项可用 `env('PG_TEST_URI', 'sqlite::memory:')`。

### 环境变量约定 {#env-compatibility}

文档与包内测试共同接受的名称：

| 用途           | 首选           | 亦可接受              |
|----------------|----------------|-----------------------|
| 通用 DB 测试 URI | `DB_TEST_URI` | `MYSQL_TEST_URI`（偏 MySQL 跑法） |
| PostgreSQL     | `PG_TEST_URI` | `PGSQL_TEST_URI`      |

示例：

```bash
export DB_TEST_URI="mysql://root:password@localhost:3306/switon_test?charset=utf8mb4"
export PG_TEST_URI="pgsql://postgres:password@localhost:5432/switon_test"
```

---

## 事务清理 {#transaction-isolation}

每个测试包在事务里，结束后 `rollback`。清理成本低，且发生在**拥有事务的那颗瞬连**上。

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
        $this->tx->rollback();  // 未 begin 时也可安全调用
        parent::tearDown();
    }
}
```

### 为何有两个 client：`$this->db` 与 `$this->tx` {#db-vs-tx}

`$this->db` 是普通 DI client；`$this->tx` 是事务里**固定到一条连接**的瞬连。二者不是同一连接，因此 **`$this->tx` 里未提交的数据，`$this->db` 默认看不见**。

测服务时要注意：

```php
class OrderServiceTest extends DatabaseTestCase
{
    public function testPlaceOrder(): void
    {
        // 通过 tx 插入夹具
        $this->tx->insert('products', ['id' => 1, 'name' => 'Widget', 'price' => 10]);

        // 服务默认拿 DI client，因此在 make 前把容器绑到 tx
        $this->container->set(ClientInterface::class, $this->tx);
        $service = $this->make(OrderService::class);

        $orderId = $service->placeOrder(userId: 1, productId: 1);
        $this->assertGreaterThan(0, $orderId);

        // 用 tx 校验（同连接 = 可见未提交数据）
        $rows = $this->tx->fetchAll('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        $this->assertCount(1, $rows);
    }
}
```

**经验法则：** 夹具若通过 `$this->tx` 写入，在 `$this->make(...)` **之前**把 `ClientInterface` 绑到 `$this->tx`。

该模式用于**隔离**，不是用来测 commit/rollback 语义本身；事务语义请单独写不经包裹的用例。

---

## 夹具 {#fixtures}

通过**参与事务的连接**插入已知数据，这样夹具随测试一起回滚：

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

## 断言什么 {#what-to-assert}

- **单元（mock）** — 断言传入了期望的 SQL 与参数（`->with(...)`），以及服务对结果的变换是否正确
- **集成** — 断言操作后的**数据库状态**：再查行数、列值等
- **错误分支** — 约束冲突等用 `$this->expectException(...)`，或 `UnsafeOperationException`

尽量少在 mock 中断言**整段 SQL 字符串**（一改格式就碎）；优先断言参数与行为。

---

## 常见坑 {#common-pitfalls}

### 用生产库跑测试 {#pitfall-prod-db}

生产数据会让测试又慢又脆又危险。

> **避免：** 把测试指向生产库。

用专用测试库或 `sqlite::memory:`；按驱动配置 `DB_TEST_URI` / `PG_TEST_URI` / `MYSQL_TEST_URI`。

### 绕过容器 {#pitfall-bypass-container}

手撕 `new Service()` 时 `#[Autowired]` 不会注入，行为与真实运行时偏离。

> **避免：** 测试里裸 `new Service()`。

优先 `$this->make(Service::class)`，由容器接线。

### 测试依赖执行顺序 {#pitfall-order-dependent}

顺序依赖会让测试随机红、维护地狱。

> **避免：** 测试之间共享可变状态。

每个用例自备数据；用 [事务隔离](#transaction-isolation) 或在 `tearDown()` 清理。

### 瞬连跨用例复用 {#pitfall-transient-leak}

瞬连握住固定连接，跨用例复用会泄漏状态、耗尽池。

> **避免：** 多个用例共用同一瞬连。

在 `setUp()` 新建瞬连，`tearDown()` 里 rollback 释放语义上的事务边界。

### 混用 DI client 与事务 client {#pitfall-db-tx}

事务隔离模式下 `$this->db` 与 `$this->tx` 不同连接；`$this->tx` 未提交的写，`$this->db` 提交前不可见。

> **避免：** 夹具走 `$this->tx`，却让服务仍拿默认 `ClientInterface`。

在 `$this->make(...)` 前把容器里的 `ClientInterface` 设成 `$this->tx`。见 [db 与 tx](#db-vs-tx)。

---

## 下一步 {#next}

- [DB 包](README.md) — client、事务与安全规则
- [Testing 包](../testing/README.md) — 测试基架与约定
- [FAQ](faq.md) — 常见问题
- [设计说明](design.md) — DB 边界为何如此划分
- [教程](../tutorial/README.md#tutorial) — 跨包的第一条应用路径
