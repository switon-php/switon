---
sidebar_label: Query 包
description: switon/query：流式读 SQL、紧凑 where、分页与分片感知执行。
---

# Query 包 {#query-package}

`switon/query` 用流式过滤器、连接、分页与分片感知执行构建**读** SQL。适用于读路径与 SQL 组合；**写**与重事务变更仍由 ORM 负责。

**关键特性：**

- **流式读取：** 在同一查询对象上链接 `select`、`where`、`groupBy`、`orderBy`、`limit`。
- **紧凑过滤器：** `where(array)` 覆盖比较、范围、`IN`、模式与原始等形式。
- **取数辅助：** `first()`、`get()`、`all()`、`value()`、`values()`、`exists()`、`count()` 及聚合。
- **分页：** `paginate()` 返回 `Switon\Query\PaginatorInterface`，带页元数据与条目。
- **分片感知执行：** 跨分片路由读请求，并在需要时合并有序或分组行。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/core`、`switon/db`、`switon/di`、`switon/sharding`

```bash
composer require switon/query
```

---

## 快速开始 {#quick-start}

为每条读路径注入 `QueryBuilderInterface`，再链接过滤器。

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Query\QueryBuilderInterface;

final class UserFinder
{
    #[Autowired] protected QueryBuilderInterface $queryBuilder;

    public function activeUsers(): array
    {
        return $this->queryBuilder->from('users')
            ->where(['status' => 'active'])
            ->orderBy(['name' => 'ASC'])
            ->limit(10)
            ->all();
    }
}
```

---

## 核心概念 {#core-concepts}

### 查询构建器 {#query-builder}

`Switon\Query\QueryBuilderInterface::from()` 为一条读路径创建新的 `QueryInterface`。可传入表名或实体类 FQCN；按**书写形态**检测。

### Where {#where}

`where(array)` 是在查询对象上做紧凑过滤的日常路径。

常见形式：

- `['status' => 'active']` 相等
- `['status' => ['active', 'pending']]` — `IN`
- `['status!=' => ['disabled', 'deleted']]` — `NOT IN`
- `['age>=' => 18]`、`['age<=' => 65]`、`['age>' => 18]`、`['age<' => 65]`
- `['created_at~=' => ['2025-01-01', '2025-12-31']]` — `BETWEEN`
- `['score%=' => [10, 3]]` — 取模校验
- `['name*=' => 'john']`、`['name^=' => 'adm']`、`['name$=' => '.com']`
- `['name,email*=' => 'john']` — 多字段 `OR` 匹配
- `[0 => 'status = 1']` — 原始表达式

若流式辅助更清晰，可使用：

- `whereEq()`、`whereCmp()`、`whereBetween()`
- `whereIn()`、`whereNotIn()`、`whereRaw()`
- `whereContains()`、`whereStartsWith()`、`whereEndsWith()`
- `whereNull()`、`whereNotNull()`

同一语法也适用于 `ConditionBuilderInterface::where()`。

### Query {#query}

`Switon\Query\QueryInterface` 为主读契约：流式过滤、连接、取数辅助、分页、聚合与分片感知执行。`select()` 中值为数组的表项按 `with()` 关联调用处理。

### 表 {#tables}

`Switon\Query\Table` 将表名与连接名配对。查询需要显式连接目标而非默认时，使用 `Table::of('users', 'replica')`。

### 分页 {#pagination}

`paginate(int $page, int $size)` 返回 `PaginatorInterface`，含 `page`、`size`、`count`、`pages` 与 `items`。为省一次 count 查询：在**非满页或空页**时，实现会从当前切片推断 `count`；**仅当页满**时才发出 `count()`。

### 分片 {#sharding}

表跨分片时，查询会路由读、校验 join 安全性，并在内存中合并有序或分组行。部分 join 或聚合形式在**非分片安全**时仍会被拒绝 — 见 [FAQ](faq.md#multi-shard)。

---

## 使用注意 {#keep-in-mind}

- `where(array)` 覆盖常见语法；形式更清晰时改用 `whereRaw()` 或流式辅助（`whereBetween()`、`whereIn()`、`whereContains()`、`whereNull()` 等）。
- 无匹配行时 `get()` 抛出 `Switon\Query\Exception\NotFoundException`；允许 `null` 时用 `first()`。
- `paginate()` 返回页元数据加条目，**不是**单纯的行切片。
- 分片读可能在内存中重排或合并行；被拒绝的 join 形式表现为 `ShardingNotSupportedException`。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#query-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **经 Repository 执行查询：** [ORM 包](../orm/README.md)
- **数据库客户端与事务：** [DB 包](../db/README.md)
- **与底层包对照分片规则：** [Sharding 包](../sharding/README.md)
