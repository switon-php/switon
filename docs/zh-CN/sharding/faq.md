---
sidebar_label: Sharding FAQ
description: Sharding：扇出、list/range、跨分片 Join 与扩容。
---

# Sharding FAQ {#sharding-faq}

**分片规则与路由相关的短答。**

> **阅读前提：** [Sharding 包](README.md)

## 入门 {#getting-started}

### 实体如何启用分片？ {#enable-sharding}

配置分片规则，并在上下文中包含分片键。

### 会自动建库建表吗？ {#create-tables}

**不会。** 只解析连接/表名。

### 为什么查询打到所有分片？ {#why-fanout}

上下文未收窄到单一分片（缺键或 `null` 等）。

### 单分片操作最少要什么？ {#single-shard-minimum}

提供规则里使用的**分片键**字段值。

## 策略 {#strategies}

### 新手选哪种？ {#choose-strategy}

数值 id → `modulo`；字符串 → `crc32` / `hash`；区间 → `range`；固定集合 → `list`。

### `list` 能按分片键值路由吗？ {#list-strategy-routing}

**不能。** `list` 忽略分片键值。

### `hash:16` 含义？ {#hash-default-algo}

`hash` 策略默认算法、16 个分片。

### `range` 无匹配区间？ {#range-no-match}

解析为**无分片**。

### `range` 分片键为 `null`？ {#range-null-fanout}

扇出到该 range 配置的**全部分片**。

### `multiple()` 表侧无分片？ {#multiple-empty-table}

返回空结果（连接侧可能已正常解析）。

### 能否改分片数量？ {#change-shard-count}

可以，但 hash 类策略会**重映射**数据，需迁移计划。

## 查询与 Join {#queries-and-joins}

### 表名看起来对，Join 仍失败？ {#join-fails}

校验看**解析后的目标**，不是表面表名。

### 能否跨分片 Join？ {#cross-shard-joins}

**不能。** 分查询后在应用层合并。

### `unique` / `multiple` / `all` 区别？ {#manager-api-difference}

单分片 / 匹配集 / 全部配置分片。

### `unique([1, 2])` 为何不像一个上下文？ {#unique-numeric-array}

数字索引数组表示**多个**上下文，且须全部落到同一分片。

### 对象上下文？ {#object-context}

用 `get_object_vars()` 读**公有**属性。

## 运维 {#operations}

### 如何快速验证路由？ {#verify-routing}

用已知分片键对比实际连接与表目标。

### 扩容/迁片计划应包含？ {#rollout-plan}

数据一致性校验、监控、延迟观察与回滚路径。
