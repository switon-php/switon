---
sidebar_label: DB 设计说明
description: 为何默认无状态 client + 显式瞬连，以及 URI 驱动读写拓扑。
---

# 设计说明 {#design-rationale}

Switon DB 把原生 SQL 放在**显式边界**上，而不是让每个应用各自发明一套风格。

## 参见 {#see-also}

- [DB 包](README.md) — 用法、配置与路由
- [FAQ](faq.md) — 常见问题

---

## 默认无状态 {#stateless-by-default}

共享的 `ClientInterface` **每次调用都会租用连接**。普通读写更简单，也更适合连接池，而且不会把事务状态藏进服务单例。

这样常见路径更好推理，同时又不会把每个服务都变成“连接所有者”。

## 本地作用域显式固定 {#explicit-local-scopes}

事务与写后读需要“一段代码、一条连接”。`getTransient('default')` 把这层边界说清楚。

共享 client 看似省事，但如果把固定连接藏起来，就很容易把无状态调用和绑连接的工作混在一起。

## ORM 事务作用域分开 {#separate-transaction-scopes}

`TransactionManagerInterface` 面向 ORM 托管的作用域；原生 SQL 要并进时，由事务管理器给出**已绑定** client，而不是让系统事后猜测。

ORM 协调和直连 SQL 仍是不同契约，但会在同一条事务边界上汇合。

## 安全写与可移植 SQL {#safe-portable-sql}

危险的全表写条件尽早失败；`[identifier]` 让手写 SQL 在各驱动间保持同一套写法。

广义更新必须在代码里写得“像是有意的”，而不是不知不觉漂移成某个驱动的引号习惯。

## URI 驱动路由 {#uri-driven-routing}

一条 URI 对应一种拓扑；多条 URI 时，首条为主，其余为从。

它比自定义路由 DSL 更小，却能覆盖常见部署：单库、固定事务 client、简单主从。

## 事件优于包裹层 {#events-over-wrappers}

数据库生命周期事件承载查询和事务观测，而不强迫业务服务塞进追踪代码。

需要指标、调试或可观测性时，用监听器或 `SqlCollector` 一类收集器挂在 SQL 执行路径上。
