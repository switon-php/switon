---
sidebar_label: Context 内部说明
description: ContextManager 与协程局部状态的实现脉络（非日常 API 入口）。
---

# Context 内部说明 {#context-internals}

当你需要**实现层面的理由**（而非日常用法）时阅读本目录：追溯 `ContextManager` 与协程局部状态如何演进。

## 何时阅读本目录 {#scope}

适合在以下问题时打开：

- 为何不能单靠 `Coroutine::getContext()` 做框架级状态管理；
- 为何 Switon 把上下文存储与发现集中到 `ContextManager`；
- 继承、隔离与运行时分支如何从具体故障模式长出来。

---

## 可用笔记 {#available-notes}

- [实现演进 walkthrough](evolution.md) — 从朴素的协程局部存储到生产设计的分步说明

---

## 下一步 {#next}

- [Context 包](../README.md) — 公开用法、隔离模式与常见陷阱
- [设计说明](../design.md) — 稳定存储与生命周期行为
