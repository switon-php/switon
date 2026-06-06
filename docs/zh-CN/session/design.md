---
sidebar_label: Session 设计说明
description: 请求内隔离、延迟写入；非大对象存储。
---

# 设计说明 {#design-rationale}

`switon/session` 让 HTTP 会话**请求内隔离**、**Redis 持久化**，并明确**何时**写存储。

## 参见 {#see-also}

- [Session 包](README.md) — 用法、配置与事件
- [FAQ](faq.md#session-faq) — 常见问题
- [Redis 包](../redis/README.md) — 默认存储后端

---

## 要解决的问题 {#core-problem}

会话状态容易被当成全局可变内存；在基于 worker 的运行时与并发请求下会失效。

## 首要目标 {#primary-goal}

提供小而统一的 API，同时由框架控制隔离、持久化时机与生命周期事件：

- 请求内数据隔离；
- 常规写入延迟到响应阶段；
- `read()` / `write()` 显式按 id 访问存储。

## 取舍 {#trade-off}

- **好处：** 控制器与服务中的会话代码保持简单。
- **好处：** 同一模型适用于多种运行时风格。
- **代价：** 调用方须尊重请求生命周期时机。
- **代价：** `read()` / `write()` 与常规请求路径分离。

## 刻意限制 {#deliberate-limits}

- 小状态，不是大对象仓库；
- 一个「当前」会话 + 可选的按 id 操作；
- 用事件观测生命周期，而非在控制器里塞自定义钩子。
