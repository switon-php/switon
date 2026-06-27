---
sidebar_label: Query FAQ
description: Query：where 语法、paginate、分片 join 与 get() 抛 NotFoundException。
---

# Query FAQ {#query-faq}

**构建查询、过滤、分页与分片相关的短答。**

> **阅读前提：** [Query 包](README.md)

## 入门 {#getting-started}

### 必须用 QueryBuilder 吗？ {#need-query-builder}

每条读路径要**新**查询实例时用。

### 何时用 `Table::of()`？ {#use-table-of}

需要把表固定到特定连接时。

### `where(array)` 能替代原始 SQL 吗？ {#where-vs-raw}

不能全覆盖；覆盖常见紧凑语法。特殊需求用原始表达式。

### `from()` 能传什么？ {#from-shape}

表名或实体类——按调用点更自然的形态选择。

## 分页 {#pagination}

### `paginate()` 返回什么？ {#paginate-return}

`PaginatorInterface`（页元数据 + `items`）。非满页可能从当前结果推断总数而不额外 count。

### Paginator 暴露哪些字段？ {#paginator-pages}

`toArray()` 返回 `page`、`size`、`count`、`pages` 与 `items`。`prev` 与 `next` 也可作为对象属性读取（无相邻页时为 `-1`）。

## 分片 {#sharding}

### Query 包做分片路由吗？ {#shard-routing}

是；可按需跨分片读并合并结果。

### 跨多分片会怎样？ {#multi-shard}

有序/聚合读可在内存合并；不安全 join/聚合会**提前拒绝**。

### 分片 join 失败？ {#sharded-join-fail}

多半跨了不兼容连接或分片；先查分片边界。重抛异常前，query 层会发出 `Switon\Query\Event\ShardValidationFailed` 供观测。

### `get()` 为什么抛异常？ {#get-throws}

单行读的「必须存在」边界；无匹配时 `NotFoundException`。
