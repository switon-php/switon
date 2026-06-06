---
sidebar_label: DB FAQ
description: DB：瞬连、主从、前缀、不安全条件、连接排错。
---

# DB FAQ {#db-faq}

**常见问题与排错。**

> **阅读前提：** [DB 包](README.md)

## 入门 {#getting-started}

### 多数应用从哪里起步？ {#default-start}

一个 `default.uri`、一个注入的 `ClientInterface`，加上命名绑定的预处理 SQL。见 [快速开始](README.md#quick-start)。

### 何时用 `getTransient('default')`？ {#transient-client}

同一本地作用域内要保证**同一连接**（事务或写后读）时用。见 [瞬连](README.md#transient-connections)。

### 何时用 `getTransient('auto')` 或 `slave`？ {#transient-read-routing}

只做读路由：`auto` 有副本则读副本；`slave` 尽力走副本，没有则回退 `default`。见 [瞬连](README.md#transient-connections)。

### 何时需要 `TransactionManagerInterface`？ {#transaction-manager}

原生 SQL 要并进 **ORM 已开启**的事务作用域时；事务管理器返回**已经绑定**的那颗 client。见 [瞬连](README.md#transient-connections)。

## 配置 {#configuration}

### 怎样配置多个数据库？ {#multiple-connections}

在 `Switon\Db\ClientInterface` 下挂多个命名的 `Switon\Di\Factory` 条目，再按属性名注入对应 client。

### 写完立刻读为什么还是旧的？ {#stale-read}

`fetchAll()` 可能走了副本。下一读要强一致时，用 `getTransient('default')` 固定作用域。

### 为什么在共享 client 上调 `begin()` 失败？ {#begin-transient}

事务 API 属于**瞬连**。先 `getTransient('default')`，再 `begin()`。

### 表前缀怎么生效？ {#prefixes}

在 URI 里设 `prefix=app_`；高层 CRUD 会自动加；手写 SQL 不包含这层魔法。

### `update()` / `delete()` 为何拒绝我的条件？ {#unsafe-condition}

空条件或过简单词条件会被拦。请写真实 SQL 表达式，或故意无操作时用 `WHERE FALSE`。

### 怎么看当前有哪些配置项？ {#inspect-options}

`bash bin/console config:options 'Switon\Db\ClientInterface'`，可看具体实现类与顶层注入属性。

### 主从怎么配？ {#wire-replicas}

`default.uri` 写多条：第一条主库，其余副本；副本连接会自动带 `readonly=1`。见 [连接拓扑](README.md#connection-topology)。

## 排错 {#troubleshooting}

### `update()` / `delete()` 仍拒绝条件？ {#unsafe-operation}

需要明确条件以避免误全表；若确实要大范围更新/删除，请用 `updateBySql()` / `deleteBySql()`。

### 连接失败先查什么？ {#connection-errors}

跑 `bash bin/console db:info` 或 `db:ping`，再核对主机、账号、池大小，以及是否有长期占着的瞬连。
