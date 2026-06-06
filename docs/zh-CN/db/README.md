---
sidebar_label: DB 包
description: switon/db：ClientInterface、命名绑定、瞬连事务与读写分离 URI。
---

# DB 包 {#db-package}

`switon/db` 为 Switon 提供**直连 SQL + 命名占位符**：默认无状态的共享客户端、本地事务用的**固定瞬连**，以及可选的**读写路由**。

**关键特性：**

- **直连契约：** 注入 `Switon\Db\ClientInterface`，使用命名绑定；
- **默认无状态：** 普通读写每次调用租用连接；
- **本地固定连接：** `getTransient('default')` 在同一作用域内保持同一连接（事务或写后读一致）；
- **读写路径：** 读可走副本，写走主库路径；
- **可移植与可观测：** `[identifier]` 引用与生命周期事件便于跨驱动与排查。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`ext-pdo`，以及目标库的 PDO 驱动。

```bash
composer require switon/db
```

---

## 快速开始 {#quick-start}

- 先配一个数据库；
- 注入 client；
- 使用参数化 SQL。

```yaml
# switon.yml
Switon\Db\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: mysql://root:password@localhost/myapp
```

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Db\ClientInterface;

final class UserService
{
    #[Autowired] protected ClientInterface $db;

    public function findById(int $id): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [name] FROM [users] WHERE [id] = :id',
            ['id' => $id],
        );

        return $rows[0] ?? null;
    }
}
```

若同一本地作用域必须共用一条连接，固定瞬连客户端：

```php
$tx = $this->db->getTransient('default');
$tx->begin();

try {
    $tx->insert('users', ['name' => 'Jane']);
    $tx->commit();
} catch (\Throwable $e) {
    $tx->rollback();
    throw $e;
}
```

- 事务与写后读一致用 `getTransient('default')`；
- `auto`、`slave` 是**读路由提示**，不是事务作用域；
- 多数应用：`default.uri` + 一个注入的 client 足够。

---

## 核心概念 {#core-concepts}

### 瞬连 {#transient-connections}

共享的 `ClientInterface` 是日常路径。

- 需要事务状态或写后读一致时，用 `getTransient('default')`；
- 仅在希望读侧**有副本则走副本**时用 `getTransient('auto')`；
- 仅在要**强制副本读**且能接受无副本时回退 `default` 时用 `getTransient('slave')`。

ORM 托管的事务作用域里，`Switon\Db\TransactionManagerInterface` 返回**已绑定**的 client，而不是再起一层瞬连。

### 读写路径 {#read-write-paths}

- `fetchAll()` 在拓扑有副本时可能读副本；
- `insert()`、`update()`、`upsert()`、`delete()` 走写路径；
- `executeInsert()` / `executeUpdate()` / `executeDelete()` 与 `insertBySql()` / `updateBySql()` / `deleteBySql()` 是直连 SQL 入口；
- `insert(..., true)` 返回自增 id，而非影响行数；
- `[identifier]` 语法让手写 SQL 在各驱动间更可移植。

### 连接拓扑 {#connection-topology}

- 从一个 `default.uri` 起步；
- 仅当不同服务需要不同库，或同一入口要主从时再扩展。

`default.uri` 写成**多条 URI** 时：第一条为主库，其余为副本。

```yaml
# switon.yml
Switon\Db\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: mysql://root:password@master/app,mysql://root:password@replica1/app,mysql://root:password@replica2/app
```

- 读可分散到副本，写仍在第一条 URI。

---

## 配置 {#configuration}

URI 形态：

```text
driver://username:password@host:port/database?options
```

常见示例：

```text
mysql://root:password@localhost/myapp
pgsql://postgres:password@localhost/myapp
sqlite:///path/to/database.db
sqlite::memory:
```

亦支持 `mssql://...`。

稳定查询选项写在 `uri` 里：

- `pool_size` — 连接池大小；`4/8` 可拆分主从池；
- `timeout` — 取连接超时；
- `prefix` — 表前缀（高层 CRUD 自动加；手写 SQL 不加）；
- `charset`、`readonly`、`emulate_prepares` — 适配器相关；
- 副本 URI 在池解析构建从连接时会自动带 `readonly=1`。

---

## 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Db\ClientInterface'
```

---

## 使用注意 {#keep-in-mind}

- `fetchAll()` 可能打到副本；要强一致下一读请固定瞬连；
- `update()` / `delete()` 会拒绝空条件与过简单词条件，防止误全表；
- 有意无删可用 `WHERE FALSE`；
- `allocateIds()` **不要**放在事务里；
- 确认当前绑定与选项面最快的方式是 `config:options`；
- `begin()` / `commit()` / `rollback()` **只能**在瞬连 client 上调用。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md)
- **设计取舍：** [设计说明](design.md)
- **测试写法：** [Testing](testing.md)
- **实现向说明（标识符引用、事务错误语义）：** [内部说明](internals/README.md)
