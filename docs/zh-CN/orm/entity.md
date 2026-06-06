---
sidebar_label: 实体
description: 属性驱动的表/键/列元数据、批量赋值、校验与动作参数绑定。
---

# 实体 {#entity-guide}

类型化实体类是 ORM 的**元数据面**：属性描述表、键、列与校验规则；**不会**自动落库。

> **阅读前提：** [ORM 包](README.md)

**关键特性：**

- **属性元数据** — 表、连接、主键、列与校验集中声明
- **类型化字段** — 公开类型属性，便于水合与序列化
- **灵活主键** — 数据库自增或 `#[Id]` 指定生成策略
- **Repository 绑定** — 默认按约定推断，非标准布局用 `#[Repository]`
- **动作参数绑定** — 实体参数经 Repository `fill()` 从请求写入
- **生命周期钩子** — 实体上 `onEvent()` 处理增删改事件
- **校验属性** — 如 `#[Unique]`、`#[Exists]`、`#[Immutable]`

## 快速开始 {#quick-start}

继承 `Switon\Orm\Entity`，加上元数据属性，再继续 [ORM 包](README.md#quick-start) 中的 Repository 路径。

```php
<?php

namespace App\Entity;

use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Entity;

#[Table('users')]
class User extends Entity
{
    #[Id]
    public ?int $id = null;

    public string $name;

    public string $email;

    public string $created_at;
}
```

写请求可直接把实体放进动作参数：

```php
#[PostMapping]
public function createAction(User $user): User
{
    return $this->userRepository->create($user);
}
```

绑定走 Repository `fill()`，仍受可填充规则与类型水合约束。日常写入可传实体，也可用数组。

另见：[分片指南](sharding.md) · [校验属性](#validation) · [ORM Codegen 包](../orm-codegen/README.md)

---

## 实体属性 {#entity}

实体级属性声明存储目标、连接与字段在读写中的行为。

### `#[Table]` {#table}

`Switon\Orm\Attribute\Table` 指定物理表名。

```php
#[Table('users')]
class User extends Entity {}
```

省略时由命名策略从类名推断。表名上的分片表达式见 [分片指南](sharding.md)。

### `#[Connection]` {#connection}

`Switon\Orm\Attribute\Connection` 指定 DB 连接；非默认库时使用。

```php
#[Connection('default')]
class User extends Entity {}
```

省略时连接为 `default`。连接上的分片表达式见 [分片指南](sharding.md)。

### `#[Id]` 与主键策略 {#id-strategies}

`Switon\Orm\Attribute\Id` 标记主键及可选生成策略。

```php
#[Id]
public ?int $id = null; // 默认 auto

#[Id(strategy: 'uuid-v7')]
public string $order_id;
```

常用策略名包括 `auto`、`snowflake`、`uuid`、`uuid-v7` 等；项目可在 `switon.yml` 中覆盖别名。

### `#[Column]` {#column}

属性名与列名不一致时映射列名；**仅**用于重命名，不控制输入。

```php
#[Column('user_name')]
public string $name;
```

### `#[Fillable]` {#fillable}

控制属性是否参与批量赋值（Repository `fill()`）。

默认仅带 `#[Id]` 或 `#[Fillable]` 的属性可填充。

```php
#[Fillable(false)]
public int $balance;
```

- `true`：可从输入填充
- `false`：排除在 fill 流程外

### `#[Transient]` {#transient}

运行时专用字段，不参与持久化元数据。

```php
#[Transient]
public ?string $display_name = null;
```

### `#[ReferencedKey]` {#referenced-key}

当外键推断名与默认不一致时，指定其他实体引用本实体时使用的键名。

```php
#[ReferencedKey('owner_ref')]
class User extends Entity
{
    #[Id]
    public int $user_id;
}
```

### `#[Repository]` {#repository}

约定无法推断 Repository 类时，显式绑定。

```php
#[Repository(CustomUserRepository::class)]
class User extends Entity {}
```

### `#[NamingStrategy]` {#naming-strategy}

控制类名/属性名到表/列的转换规则；显式 `Table` / `Column` 优先于策略推断。

```php
#[NamingStrategy(NamingStrategy::CAMEL)]
class User extends Entity {}
```

### `#[DateFormat]` {#date-format}

统一日期字段的水合与存储格式（如 `U`、`Y-m-d H:i:s`）。

```php
#[DateFormat('Y-m-d H:i:s')]
class User extends Entity {}
```

---

## 校验属性 {#validation}

ORM 实体上内置的校验属性；更多规则见 [Validation 包](../validation/README.md)。

### `#[Unique]` {#unique}

校验字段在库中唯一，可选作用域过滤。

```php
#[Unique]
public string $email;

#[Unique(filters: ['tenant_id'])]
public string $username;
```

### `#[Exists]` {#exists}

校验值在目标实体中作为主键存在；适用于「有值才校验」的外键。

```php
#[Exists(User::class)]
public ?int $user_id = null;
```

### `#[Immutable]` {#immutable}

创建后禁止更新该字段。

```php
#[Immutable]
public string $order_no;
```

---

## 注意 {#keep-in-mind}

- 持久化字段用**公开类型属性**。
- 列重命名用 `#[Column]`；批量赋值范围用 `#[Fillable]`。
- 常规 Repository 写入背后都会走 `fill()`。
- 目录布局不符合约定时显式 `#[Repository(...)]`。
- 时间、数值字段选定一种表示，在实体、表结构与序列化层保持一致。

---

## 下一步 {#next}

- [ORM 包](README.md) — 入门路径、Repository API、过滤与边界
- [分片指南](sharding.md#sharding-guide) — `#[Connection]` / `#[Table]` 上的分片表达式
- [校验属性](entity.md#validation) — 实体上的校验规则
- [Sharding 包](../sharding/README.md) — 规则语法、管理器 API、Join 校验
- [Query 包](../query/README.md) — Repository 过滤语法
- [DB 包](../db/README.md#db-package) — 客户端与事务钉扎
- [ID 包](../id/README.md#id-package) — 生成器行为与要求
- [ORM Codegen 包](../orm-codegen/README.md) — 从表结构脚手架实体与 Repository
- [FAQ](faq.md#orm-faq) — 常见问题与排错
- [设计说明](design.md#design-rationale) — ORM 架构与取舍
