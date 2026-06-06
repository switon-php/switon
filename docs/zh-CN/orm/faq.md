---
sidebar_label: ORM FAQ
description: ORM：与 Query/DB 分工、显式 update、关联加载与分片写入。
---

# ORM FAQ {#orm-faq}

**接入与用法相关的短答。**

> **阅读前提：** [ORM 包](README.md)

## 入门 {#getting-started}

### 用 ORM、Query 还是 DB？ {#orm-query-db}

要类型化实体与 Repository 边界 → **ORM**；要无实体的临时查询组合 → **Query**；要原始 SQL 或更低层事务 → **DB**。

### 改完实体要调 `update()` 吗？ {#explicit-update}

要。内存修改**不会**自动持久化。

### `get()` 还是 `find()`？ {#get-vs-find}

行必须存在 → `get()`；`null` 可接受 → `find()`。

### `first()` 还是 `firstOrFail()`？ {#vs-fail}

可无匹配 → `first()`；必须有匹配 → `firstOrFail()`。

## 关联 {#relations}

### 访问属性会懒加载吗？ {#lazy-proxy}

**不会**。在 Repository 调用里请求关联。见 [关联指南](relations.md)。

### 如何预加载？ {#eager-load}

在 `fields` 里写关联名，如 `['posts' => ['id', 'title']]`。

### 规则放哪？ {#relation-rules}

元数据在**实体**；加载形状在 **Repository 调用**。

### 支持哪些形状？ {#supported-relations}

见 [关联指南](relations.md)。

## 分页 {#pagination}

### 如何从请求绑定 `Page`？ {#page-binding}

动作参数类型提示 `Switon\Orm\Page`，读当前输入的 `page`/`size`。

### 默认值？ {#page-defaults}

`page=1`，`size=10`；小于 1 失败；默认 `page*size` 上限 1000；单动作用 `PageLimit`。

## 分片 {#sharding}

### 写入无法落到单一分片？ {#sharding-too-many}

快速失败；调用 Repository 前提供分片驱动字段。

### 批量 update/delete 会跨分片吗？ {#bulk-shard}

会。`updateAll()`/`deleteAll()` 走查询式执行，可扇出。

### ORM 会建分片表吗？ {#shard-schema}

**不会**。先准备好物理 schema。

## 配置 {#configuration}

### 看哪些选项？ {#inspect-options}

分页：`config:options 'Switon\Orm\PageResolver'`；DB：`Switon\Db\ClientInterface`。

### `fill()` 为何忽略 `null`？ {#fill-null}

`null` 表示未提供，不写 SQL `NULL`；要显式 NULL 用更低层路径。
