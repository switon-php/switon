---
sidebar_label: ORM 包
description: switon/orm：实体、Repository 边界、显式关联加载与分片路由。
---

# ORM 包 {#orm-package}

`switon/orm` 提供**类型化实体**、**以 Repository 为先**的持久化、**显式**关联加载与**分片感知**路由。

**关键特性：**

- **Repository 边界：** 应用代码落在 `App\Repository\*Repository` 子类上；
- **实体元数据：** 表、键、列、关联与校验声明在实体类上；
- **显式加载关联：** 在 Repository 调用中指定关联图；
- **分片路由：** 写入与批量插入前先解析到**单一**目标；
- **分页绑定：** `Page` 从请求绑定 `page` / `size`。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/core`、`switon/db`、`switon/query`、`switon/sharding`、`switon/validation`、`switon/event`、`switon/di`、`switon/binding`、`switon/id`、`switon/principal`

```bash
composer require switon/orm
```

---

## 快速开始 {#quick-start}

定义一个实体、一个 Repository、一个服务方法。

```php
#[Table('users')]
class User extends Entity
{
    #[Id]
    public ?int $id = null;

    #[Fillable]
    public string $name;

    #[Fillable]
    public string $email;
}
```

```php
/** @extends Repository<User> */
class UserRepository extends Repository
{
}
```

```php
class UserService
{
    #[Autowired] protected UserRepository $userRepository;

    public function register(string $name, string $email): User
    {
        $user = new User();
        $user->name = $name;
        $user->email = $email;

        return $this->userRepository->create($user);
    }
}
```

要点：

- 元数据在实体类；
- 读写由 Repository 方法负责；
- **内存里改实体不会自动落库**。

---

## 核心概念 {#core-concepts}

### 实体模型 {#entity-model}

`Entity` 是类型化记录模型，承载表、键、列、关联与校验元数据。详见 [实体指南](entity.md)。

### Repository 边界 {#repository-boundary}

`RepositoryInterface` 是应用侧读写边界：`get`/`find`/`all`/`paginate`/`create`/`update`/`delete`/`save`/`put` 等。

- `create()` — 校验、自动填充、插入后重载库默认值；
- `put()` — **跳过**常规写管道，仅用于可信的完整数据，仍发 create 事件；
- `save()` — 有主键走 `update()`，否则 `create()`；
- `update()` — 先加载原行再比较与校验。

### 关联加载 {#relation-loading}

实体声明关联**形状**；Repository 调用决定**加载什么**。见 [关联指南](relations.md)。

### 分页 {#page-binding}

动作参数类型提示 `Page`，Repository 侧 `paginate()`。默认 `page=1`、`size=10`；小于 1 绑定失败；默认 `page*size` 上限 **1000**；单动作可用 `#[PageLimit]`。选项：`config:options 'Switon\Orm\PageResolver'`。

### 分片 {#sharding}

元数据声明分片；读可扇出，**写须单一目标**。`createMany()` 要求整批同一分片；`updateAll()`/`deleteAll()` 可走查询式扇出。见 [分片指南](sharding.md)。

---

## 配置 {#configuration}

默认即可；需要时再配置 DB 客户端、分页与代码生成扫描。

### 数据库接线 {#database-wiring}

ORM 通过容器解析 `Switon\Db\ClientInterface`；具体客户端选项属于 DB 包。

```bash
bash bin/console config:options 'Switon\Db\ClientInterface'
```

### 代码生成 {#code-generation}

可选包 `switon/orm-codegen` 从数据库元数据脚手架实体与 Repository。

---

## 使用注意 {#keep-in-mind}

- 内存修改后须显式 `create`/`update`/`delete`；
- 在 Repository 调用里**显式**请求关联；
- `put()` 仅用于可信完整数据；
- `fill()` 把 `null` 视为「未提供」，不会写 SQL `NULL`；
- 数组 `create()` 会清主键再插入；
- 分片实体写入前准备好分片驱动字段。

---

## 下一步 {#next}

- **实体与关联映射：** [实体指南](entity.md#entity)
- **关联形状与加载：** [关联指南](relations.md#relation-guide)
- **分片路由：** [分片指南](sharding.md#sharding-guide)
- **无实体场景的即席 SQL：** [Query 包](../query/README.md)
- **直连 SQL、事务与客户端接线：** [DB 包](../db/README.md)
- **常见问题：** [FAQ](faq.md#orm-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
