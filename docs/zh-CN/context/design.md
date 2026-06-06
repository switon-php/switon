---
sidebar_label: Context 设计说明
description: 为何用「归属 + Context」界定可变状态，而不是通用容器或 attribute。
---

# 设计说明 {#design-rationale}

`switon/context` 通过让**归属服务**一次性声明作用域边界，把可变运行时状态从共享服务里挪出去。

## 参见 {#see-also}

- [Context 包](README.md#context-package) —— 用法、边界与首次接入
- [FAQ](faq.md#context-faq) —— 常见问题
- [Context 内部说明](internals/README.md) —— 需要实现细节时的演变笔记
- [HTTP 请求](../http/request.md#request) —— 一步递值时的请求属性
- [Core 包](../core/README.md) —— `ContextAware`、`ContextManagerInterface` 与 `#[Communal]`

---

## 要解决的问题 {#core-problem}

可变服务状态常常是结构最先走样之处：

- 有服务开始把请求数据往自己身上堆；
- 各运行时又各自搞一套状态模型；
- 子协程有时共享、有时不共享；
- 清理规则藏在隐式行为里。

框架需要一条**在用户代码里可见**、且在多运行时下**可预期**的状态边界。

## 首要目标 {#primary-goal}

先统一「按**归属**划作用域」这一种模型，再由各运行时决定如何落存储。

- **好处：** 同一套服务代码可跑在 FPM、Swoole、常驻 worker；
- **好处：** 继承子作用域与隔离子作用域共用同一套 API；
- **代价：** 可变状态必须走显式的 context 归属契约。

## 核心选型 {#core-choice}

把**服务**当归属，把 **Context 对象**当可变状态容器。

- `ContextAware` 标记归属；
- `ContextManagerInterface` 在当前运行时作用域解析「活跃」Context；
- `ContextIsolated` 在「共享不安全」时改写子协程边界。

应用侧只学一条路径，而不是多种存储 API。

## 取舍 {#trade-off}

本包**不是**通用应用容器。

- **好处：** 归属清晰；
- **好处：** 运行时行为更好推理；
- **代价：** 只想「递一下」的值仍更适合请求 attribute 或其它更薄边界。

## 刻意保持简单 {#deliberate-simplicity}

把常见应用路径压得很短：

- 归属服务调用 `getContext()`；
- 默认子作用域继承父状态，除非另行标记；
- 可观测性独立于主契约。

这足以覆盖多数应用状态，而不会长成另一套存储框架。
