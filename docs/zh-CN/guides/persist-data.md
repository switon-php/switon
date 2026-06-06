---
description: 将内存状态替换为持久化存储，并在 ORM、DB 与 Query 之间为应用选择合适的数据层。
---

# 持久化数据 {#persist-data}

当你希望用可持久存储替换内存状态，并在读写关注点扩散到整个应用之前选定合适的 Switon 数据层时，使用本指南。

## 选对层 {#layer}

先选择与眼前真实问题匹配的层。

| 层 | 最适合 | 注意 |
|---|---|---|
| [ORM](../orm/README.md) | 应用主边界是实体、仓储与显式 CRUD | 存储业务数据的默认首选；无隐藏 flush，无懒加载关联读取 |
| [DB](../db/README.md) | 一条固定 SQL 或一次显式事务比仓储抽象更清晰 | 注入的客户端无状态；单次操作需要固定 transient 句柄做事务时用 `getTransient('default')` |
| [Query](../query/README.md) | 动态筛选、列表/搜索界面或管理端式查询组合是难点 | 适合组装读侧过滤、排序、分页与搜索规则；不能全面替代仓储主导的写入 |

一种常见的应用形态是：

- **ORM** 负责常规写入与仓储主导的读取
- **Query** 负责动态列表端点与搜索/筛选界面
- **DB** 负责固定 SQL、固定事务或更低层数据工作

## 建议的构建顺序 {#build-order}

1. 先配置一个 DB 客户端（`Switon\Db\ClientInterface`；多数应用第一条路径只需 `default.uri`）。
2. 选定主要驻留边界：实体/仓储思维用 [ORM](../orm/README.md)，固定 SQL 或显式事务用 [DB](../db/README.md)，动态筛选/列表组合用 [Query](../query/README.md)。
3. 在泛化之前先持久化**一条**小路径（创建 + 列表即可），不要先把关联、报表或导入全部设计完。
4. 仅当读侧组合成为真实问题时再引入 [Query](../query/README.md)。
5. 固定 SQL 或显式事务再下沉到 [DB](../db/README.md)；transient 句柄保持局部。
6. 在分片、代码生成或缓存等高级优化之前收手，先讲清一条存储路径。

## 最小接线 {#minimal-wiring}

仅用简短代码展示各数据层之间的接缝。

下文中的 `App\Entity\User`、`UserRepository`、`UserService` 等应用级名称仅为示例。Switon 框架符号与 API 形态才是稳定部分。

### 一个 DB 客户端基础 {#wiring-client}

三层都从同一份 DB 客户端定义起步：

```yaml
# switon.yml (project root)
Switon\Db\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: ${DATABASE_URL}
```

对第一条存储路径已足够。仅在应用真正需要时再增加连接或拓扑。

### 仓储优先的 ORM 路径 {#wiring-orm}

当存储的业务数据应落在仓储之后时，从这里开始。
在依赖下方实体映射之前，假定表或 schema 已存在；若仍缺 schema 步骤，请先继续 [ORM](../orm/README.md) 与 [实体指南](../orm/entity.md)。
本指南不引入框架自带的单一迁移工具。对初学者更实用的规则更简单：

- 通过 `DATABASE_URL` 选择数据库，例如 `mysql://...`、`pgsql://...` 或 `sqlite:...`
- 在运行仓储代码之前，用项目现有迁移路径或一条显式 SQL 脚本建表
- 若仍在本地验证形态，SQLite 作为第一条存储路径的首个数据库完全合适

```php
namespace App\Entity;

use Switon\Orm\Attribute\Column;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Entity;

#[Table('users')]
class User extends Entity
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;

    #[Column]
    public string $email;
}
```

```php
namespace App\Repository;

use App\Entity\User;
use Switon\Orm\Repository;

/** @extends Repository<User> */
class UserRepository extends Repository
{
    protected string $entityClass = User::class;
}
```

```php
use Switon\Core\Attribute\Autowired;

class UserService
{
    #[Autowired] protected UserRepository $users;

    public function register(string $name, string $email): User
    {
        return $this->users->create([
            'name' => $name,
            'email' => $email,
        ]);
    }
}
```

这样应用代码留在仓储层，写入保持显式。
若仓储与实体已遵循默认的 `App\Repository\UserRepository` -> `App\Entity\User` 命名，Switon 可自动推断实体类，首轮可省略显式 `$entityClass` 行。

若项目遵循框架软删除约定，此处也应保持策略显式：`deleted_at = 0`
表示未删除，其他值表示删除时间。这是 `Switon\Http\Attribute\SoftDeleteFilter` 等辅助所用的约定，并非 ORM 会为每个仓储自动打开的开关。当规则应对所有调用方生效而不仅是一个 HTTP 端点时，在仓储策略中加入该过滤器。

### 用 Query 做动态读路径 {#wiring-query}

当列表与搜索条件变得嘈杂时，引入 [Query](../query/README.md)：

```php
use Switon\Core\Attribute\Autowired;
use Switon\Query\QueryBuilderInterface;

class UserService
{
    #[Autowired] protected QueryBuilderInterface $queries;

    public function listActiveUsers(string $keyword = ''): array
    {
        return $this->queries->from('users')
            ->where([
                'status' => 'active',
                'name*=' => $keyword,
            ])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(20)
            ->all();
    }
}
```

当条件组合是难点时使用该层，而非在仓储或一条 SQL 已足够清晰时。
此处 `'name*=' => $keyword` 使用 Query 筛选语法：`*=` 表示「包含」，Query 会编译为类似 `LIKE '%keyword%'`。该运算符来自 [Query](../query/README.md)，而非 PHP 本身。

若列表默认应隐藏软删除行，在查询中显式加上该条件，或在 HTTP 侧用 `Switon\Http\Attribute\SoftDeleteFilter` 注入；Query **不会**自动添加 `deleted_at = 0`。

### 用 DB 做显式事务路径 {#wiring-db}

需要固定连接与明显事务边界时，下沉到 [DB](../db/README.md)。
假定下方 `$this->db` 为已注入的 `Switon\Db\ClientInterface`。

```php
$tx = $this->db->getTransient('default');

try {
    $tx->begin();
    $orderId = $tx->insert('orders', ['user_id' => 1, 'total' => 100], true);
    $tx->insert('order_items', ['order_id' => $orderId, 'product_id' => 42, 'quantity' => 2]);
    $tx->commit();
} catch (\Throwable $e) {
    $tx->rollback();
    throw $e;
}
```

将 transient 句柄放在局部变量中。共享的、由 DI 管理的客户端是常规无状态路径。

## 初期应避免什么 {#pitfalls}

有意让第一条持久化路径保持狭窄。

过早避免：

- **一次性设计所有表与关联** — 在泛化模型之前先证明一条存储路径
- **在读侧组合尚未变难时就使用 `Query`** — 固定读取应保持固定
- **为常规仓储工作直接写原始 SQL** — 当实体与仓储已能很好表达工作时，使用 [ORM](../orm/README.md)
- **假定自定义命名下仓储推断永远有效** — 若仓储与实体不再遵循默认命名对，显式设置 `protected string $entityClass = User::class;`
- **在长生命周期服务上保留 transient DB 句柄** — 固定连接应属于短暂的局部事务范围
- **先上分片、代码生成或缓存层** — [Sharding](../sharding/README.md)、[ORM Codegen](../orm-codegen/README.md) 与 [Cache](../cache/README.md) 应在第一条存储路径稳定之后

完成第一条持久化路径后，初学者应能感到：

> 我知道应用代码应落在哪里，也知道何时应下沉到更低层的数据工具。

## 延伸阅读 {#go-deeper}

- **在第一条 API 或页面路径之后衔接本指南：** [构建 API](build-an-api.md#build-an-api) · [构建 HTML 页面](build-html-pages.md#build-html-pages)
- **在请求路径已真实存在后，一次性放置请求级钩子、action 包装或 HTTP 失败策略：** [添加横切 HTTP 行为](add-cross-cutting-http-behavior.md#add-cross-cutting-http-behavior)
- **在认证、缓存或任务增长之前保护第一条存储路径：** [为已有应用添加测试](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
- **在不暴露文件系统路径的前提下添加文件元数据与 blob 存储：** [处理文件上传与下载](handle-file-uploads-and-downloads.md#handle-file-uploads-and-downloads)
- **按章节学习同样的迁移：** [04 — 存储数据](../tutorial/04-data.md#04-data)
- **下一步从控制台复用已存储的服务：** [添加 CLI](add-cli.md#add-cli) · [05 — CLI 复用](../tutorial/05-cli.md#05-cli) · [CLI](../cli/README.md)
- **在存储稳定后添加登录态、Bearer 令牌或受保护端点：** [添加认证](add-authentication.md#add-authentication) · [Session](../session/README.md) · [JWT](../jwt/README.md) · [Authz](../authz/README.md)
- **在存储稳定后添加缓存读路径：** [添加缓存](add-caching.md#add-caching) · [更快的列表](../tutorial/faster-list.md#faster-list) · [Cache](../cache/README.md)
- **继续以仓储主导的实体与 CRUD 为主：** [ORM](../orm/README.md) · [实体指南](../orm/entity.md)
- **使用固定 SQL 或显式事务：** [DB](../db/README.md)
- **组合动态列表与搜索查询：** [Query](../query/README.md)
- **日后扩展到 schema 驱动辅助或分片感知数据工作：** [ORM Codegen](../orm-codegen/README.md) · [Sharding](../sharding/README.md)
