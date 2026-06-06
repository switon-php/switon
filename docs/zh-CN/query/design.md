---
sidebar_label: Query 设计说明
description: 谓词解析、SqlBuilder、ShardRouting 与 ShardMerger 分层。
---

# 设计说明 {#design-rationale}

`switon/query` 将查询工作拆为：**谓词解析**、**SQL 构建**、**分片路由**、**内存结果合并**。

## 参见 {#see-also}

- [Query 包](README.md) — 用法、概念与边界
- [Query FAQ](faq.md#query-faq) — 短答与分片相关问题

---

## 要解决的问题 {#core-problem}

查询代码常同时向三处膨胀：

- 过滤语法难以保持一致；
- SQL 组装绑死在一种库侧形态；
- 分片读与普通读缠在一起。

`switon/query` 分离这些关注点，使常见读路径保持流式，困难情形保持显式。

## 为何拆分 {#split}

- `ConditionBuilder` / `PredicatePipeline` — 紧凑过滤 → 规范化谓词；
- `SqlBuilder` — 子句 → 单连接 SQL；
- `ShardRouting` — 决定触达哪些表/分片；
- `ShardMerger` — 跨分片时在内存合并有序/索引/分组行。

各部分更小、更易替换。

## 刻意不隐藏 {#hide}

本包**不**假装每种查询形式都分片安全。

部分 join、分组与聚合仍须**提前失败**，而非猜测。因此设计保持校验可见，而非试图自动纠正所有情况。

## 公共 API 保持流式 {#public-api-fluent}

面向常见读路径：从表/实体出发 → 过滤与 join → 选一种取数形式 → 分页或聚合。应用侧一条可读路径，内部处理解析、SQL 与分片协调。
