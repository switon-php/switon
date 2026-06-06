---
sidebar_label: HTTP Cache 设计说明
description: 为何在 HTTP 边界做声明式页面缓存，RequestReady 与 HeadersSending 分工。
---

# 设计说明 {#design-rationale}

`switon/http-cache` 在 **HTTP 边界**统一页面缓存，而不是把逻辑散落在控制器里。

## 参见 {#see-also}

- [HTTP Cache 包](README.md#http-cache-package) —— 用法、配置与首次接入
- [FAQ](faq.md#http-cache-faq) —— 未命中与接线问题

---

## 要解决的问题 {#core-problem}

页面缓存常漂进控制器、请求状态与临时 `ETag` 处理，键容易错、跨请求行为难审计。

## 首要目标 {#primary-goal}

缓存声明放在**动作**上，生命周期工作放在**过滤器**里。

- **好处：** 缓存边界在 handler 上可见；
- **好处：** 再验证与存储在各动作间一致；
- **代价：** 隐藏状态仍须在 `key` 里写清楚。

## 核心选型 {#core-choice}

在 HTTP 生命周期上拆分「查找」与「写入」：

- `Switon\Http\Event\RequestReady` — 查找与再验证；
- `Switon\Http\Event\HeadersSending` — 写入缓存与响应头。

动作保持简单，缓存状态请求级隔离。

## 取舍 {#trade-off}

优先**请求派生键**与 **Redis 共享页面体**，而非更灵活的按目的地路由。

- **好处：** 多进程间易复用重复页面响应；
- **好处：** `304` 与 HTTP 语义一致；
- **代价：** 用户/会话差异须设计进 `key`，或不要走页面缓存。
