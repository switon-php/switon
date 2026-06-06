---
sidebar_label: DB 内部说明
description: 跨驱动标识符引用与事务错误处理（README/design 之外的实现向说明）。
---

# DB 内部说明 {#db-internals}

在 [DB 包](../README.md) 与 [设计说明](../design.md) 之外，用于追溯**跨驱动 SQL 行为**与**事务回滚语义**的实现向笔记；**不是**首次接入路径。

## 何时阅读 {#scope}

适合在以下场景打开本目录：

- 排查各驱动下标识符引用、SQL 文本如何被转换；
- 调试事务嵌套、`rollback` 为何有时静默、有时抛错；
- 做包内实现审阅，而不是学日常用法。

---

## 可用笔记 {#available-notes}

- [标识符引用设计](identifier-quoting-design.md) — 手写 SQL 为何用 `[identifier]`，各适配器如何转换；
- [事务错误处理设计](transaction-error-handling-design.md) — `ClientInterface` 与 `TransactionManagerInterface` 在嵌套 `begin()`、无活动事务 `rollback()` 上的差异。

---

## 下一步 {#next}

日常用法请回到包级文档：

- [DB 包](../README.md)
- [设计说明](../design.md)
- [FAQ](../faq.md)
