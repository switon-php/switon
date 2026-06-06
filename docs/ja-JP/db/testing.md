---
sidebar_label: DB テスト
description: ClientInterface のモック・統合・トランザクション隔離。
---

# データベースコードのテスト

`Switon\Db\ClientInterface` に依存するコード向けのパターン：高速な単体テストではクライアントをモックし、SQL や制約を実証する必要があるときは実ドライバを登録し、1 本にピン留めしたクライアント上で安価なロールバックによるクリーンアップが欲しいときは統合テストをトランザクションで包む。

**主な機能:**

- **モック優先の単体テスト** — テストコンテナで `ClientInterface` を差し替え、PHPUnit の期待で呼び出しを検証
- **実ドライバの統合** — SQLite インメモリやスイートが所有するドライバ URI を持つ `Switon\Di\Factory` を登録
- **トランザクションによるクリーンアップ** — 1 本にピン留めしたクライアント上で、`getTransient()` + `begin()` / `rollback()` による高速なロールバックベースのクリーンアップ

> **前提:** [DB パッケージ](README.md) · [Testing パッケージ](/en/testing/)

関連: [DB パッケージ — クイックスタート](README.md#quick-start) · [Transient 接続](README.md#transient-connections)

## どの方式を選ぶ？ {#which-approach}

証明したい内容を満たすうえで、最も軽い方式を選ぶ。

- **ビジネスロジックと分岐** — モックを使う [単体テスト](#unit-tests)
- **SQL 構文、制約、ドライバの挙動** — 実データベースを使う [統合テスト](#integration-tests)
- **多数の実 DB ケースを安価にクリーンアップ** — [トランザクションによるクリーンアップ](#transaction-isolation)

---

## 単体テスト（モック） {#unit-tests}

コンテナで `ClientInterface` をモックし、`$this->make()` でサービスを生成する。`#[Autowired]` プロパティは自動注入される。

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

サービスの挙動に対して素早いフィードバックが欲しいときに使う。SQL・インデックス・制約が正しいことを証明する用途には使わない。

---

## 統合テスト（実データベース） {#integration-tests}

`setUpContainer()` で `Switon\Di\Factory` 経由に実データベースを登録する。`$this->make()` で生成したサービスはすべて実クライアントを受け取る。

### SQLite インメモリ {#sqlite}

最速の実 DB 構成：外部サービス不要、スキーマ作成が即時、クリーンアップが容易。

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

SQLite はリポジトリテストに優れるが、MySQL / PostgreSQL の挙動をすべてカバーするわけではない。特定ドライバが必要なときは、テストコンテナを実データベース URI に向ける：

```php
protected function setUpContainer(): void
{
    (new Factory([
        'default' => ['uri' => env('DB_TEST_URI', 'sqlite::memory:')],
    ]))->register(ClientInterface::class, $this->container);
}
```

PostgreSQL 固有のテストでは `env('PG_TEST_URI', 'sqlite::memory:')` を使う。

### 環境変数の互換性 {#env-compatibility}

ドキュメントとパッケージのテストスイート全体で、次の名前が受け入れられる：

| 用途                 | 推奨          | 併用可                                  |
|----------------------|---------------|-----------------------------------------|
| 汎用 DB テスト URI   | `DB_TEST_URI` | MySQL 向け実行では `MYSQL_TEST_URI` も可 |
| PostgreSQL テスト URI | `PG_TEST_URI` | `PGSQL_TEST_URI`                        |

例:

```bash
export DB_TEST_URI="mysql://root:password@localhost:3306/switon_test?charset=utf8mb4"
export PG_TEST_URI="pgsql://postgres:password@localhost:5432/switon_test"
```

---

## トランザクションによるクリーンアップ {#transaction-isolation}

各テストをトランザクションで包み、終了後にロールバックする。トランザクションを所有する transient クライアント上でクリーンアップを安価に保てる。

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

### 2 つのクライアント：`$this->db` と `$this->tx` {#db-vs-tx}

`$this->db` は通常の DI 管理クライアント。`$this->tx` はトランザクション内で 1 接続にピン留めされた transient。別接続のため、`$this->tx` の未コミット書き込みは `$this->db` からは見えない。

サービスをテストするときはこれが重要：

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

**目安:** フィクスチャを `$this->tx` 経由で挿入したなら、`$this->make(...)` の前に `ClientInterface` を `$this->tx` にバインドする。

このパターンは隔離のためであり、コミット / ロールバック自体をテストするためではない。トランザクションの意味論は、ラップしない別テストで検証する。

---

## フィクスチャ {#fixtures}

既知データはトランザクション付き接続経由で挿入し、テストと一緒にロールバックされるようにする：

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

## 何を検証するか {#what-to-assert}

- **単体テスト（モック）** — 正しい SQL とパラメータが渡されたこと（`->with(...)`）と、サービスが結果を正しく返す / 変換することを検証
- **統合テスト** — 操作後の**データベース状態**を検証：クエリし直して行数・件数・カラム値を確認
- **エラーケース** — 制約違反や `UnsafeOperationException` には `$this->expectException(...)` を使う

可能ならモックで SQL 文字列そのものを厳密一致させない — フォーマット変更で壊れやすい。パラメータと挙動を検証する。

---

## よくある落とし穴 {#common-pitfalls}

データベースコードのテストで避けるべき典型的なミス。

### 本番データベースをテストに使う {#pitfall-prod-db}

本番データを使うと、テストは不安定・遅い・危険になる。

> **避ける:** テストを本番データベースに向けること。

専用のテスト DB か `sqlite::memory:` を使う。検証するドライバに応じて `DB_TEST_URI` / `PG_TEST_URI` / `MYSQL_TEST_URI` で設定する。

### コンテナを迂回する {#pitfall-bypass-container}

サービスを手動で `new` すると `#[Autowired]` が注入されず、実ランタイムと挙動がずれる。

> **避ける:** テスト内の `new Service()`。

`$this->make(Service::class)` を優先する。コンテナが `#[Autowired]` を自動配線する。

### 実行順に依存するテスト {#pitfall-order-dependent}

順序依存のテストはランダムに失敗し、保守が難しくなる。

> **避ける:** テスト間で状態を共有すること。

各テストは自前でデータを作る。[トランザクション隔離](#transaction-isolation) か `tearDown()` でクリーンアップする。

### テスト間で transient を保持する {#pitfall-transient-leak}

transient クライアントは接続をピン留めする。テスト間で再利用すると状態が漏れ、プールを枯渇させる。

> **避ける:** 複数テストにわたって transient を生かし続けること。

`setUp()` で新しい transient を作り、`tearDown()` でロールバックして解放する。

### DI クライアントとトランザクションクライアントの混在 {#pitfall-db-tx}

トランザクション隔離を使うとき、`$this->db` と `$this->tx` は別接続。`$this->tx` への書き込みは、コミット前は `$this->db` から見えない。

> **避ける:** フィクスチャは `$this->tx` 経由なのに、サービスは既定の `ClientInterface` のまま作ること。

`$this->make(...)` の前にコンテナの `ClientInterface` を `$this->tx` に再バインドする。詳細は [db と tx](#db-vs-tx)。

---

## 次へ {#next}

- [DB パッケージ](README.md) — クライアント、トランザクション、安全ルール
- [Testing パッケージ](/en/testing/) — テストハーネスと規約
- [FAQ](faq.md) — よくある質問とトラブルシュート
- [設計の根拠](design.md) — DB 境界がこの形である理由
- [チュートリアル](/en/tutorial/) — パッケージ横断の最初のアプリ経路
