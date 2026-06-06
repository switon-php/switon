---
sidebar_label: Session FAQ
description: Session：何时启动、延迟写入、ttl/lazy 与 destroy 模式。
---

# Session FAQ {#session-faq}

**会话接线与排错相关的短答。**

> **阅读前提：** [Session 包](README.md)

## 入门 {#getting-started}

### 会话何时真正开始？ {#when-session-starts}

**首次访问** session API 时。若 cookie 指向已过期或空记录，会生成新 session id。

### 新会话何时写入存储？ {#when-session-persists}

有数据需要保存时，在**响应头发送**阶段持久化，而不是 `set()` 调用当下。

### `has()` 对 `null` 返回 true？ {#has-null-values}

**不会。** `null` 视为不存在。

## 配置 {#configuration}

### 在哪里配置？ {#where-configured}

`switon.yml` 中的 `Switon\Session\SessionInterface`。

### `ttl` 与 `params.expire` 区别？ {#ttl-vs-expire}

`ttl` 管 **Redis 存储**寿命；`params.expire` 管 **cookie** 寿命。

### `lazy` 做什么？ {#what-is-lazy}

降低无变化会话的重复写；`lazy > 0` 时在存储数据里维护内部 `__T` 时间戳。

### 如何查看全部选项？ {#discover-options}

`bash bin/console config:options 'Switon\Session\SessionInterface'`

## 运行时 {#runtime-behavior}

### 为什么 `set()` 后立刻读 Redis 没有数据？ {#not-written-immediately}

正常路径在**响应末尾**才写。

### `read()` / `write()` 边界？ {#direct-storage-boundary}

按 session id 操作存储，**不**自动更新当前 cookie；JSON 载荷；`write()` 使用当前请求的 TTL 覆盖。

### `destroy()` 与 `destroy($id)`？ {#destroy-modes}

前者销毁**当前**会话；后者只删指定 id 的存储，不影响当前 cookie。

## 排错 {#troubleshooting}

### 每次都是新会话 {#always-new}

查客户端 cookie、作用域、Redis 键前缀、存储是否过期或被清。

### 关浏览器数据就没了 {#browser-close}

可能是**会话级 cookie**（无持久 expire）。

### `destroy($id)` 后浏览器 cookie 还在 {#destroy-id-cookie}

预期行为：只删该 id 的存储。

### 如何快速查看某条存储？ {#inspect-one-session}

`read($sessionId)`；反序列化失败时返回空数组。
