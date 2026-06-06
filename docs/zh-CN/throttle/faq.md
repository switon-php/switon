---
sidebar_label: Throttle FAQ
description: Throttle：规则格式、burst、prefix 与 429 排错。
---

# Throttle FAQ {#throttle-faq}

**限速相关的短答。**

> **阅读前提：** [Throttle 包](README.md)

## 入门 {#getting-started}

### 如何给动作加速率限制？ {#add-rate-limiting}

在类或方法上加 `#[RateLimit('10/m')]`。

### 非 HTTP 如何限流？ {#non-http}

注入 `RateLimiterInterface` 并调用 `hit()`。

### 超限会怎样？ {#limit-exceeded}

抛出 `Switon\Throttle\Exception\RateLimitExceededException`；HTTP 流程中一般为状态码 **429**。

## 配置 {#configuration}

### 默认规则在哪配？ {#configure-defaults}

`switon.yml` 中的 `Switon\Throttle\RateLimiterInterface`。

### 如何关闭自动请求限流？ {#disable-automatic}

`enabled: false`；**不影响** `hit()`。

### 如何查看选项？ {#discover-options}

`bash bin/console config:options 'Switon\Throttle\RateLimiterInterface'`

## 规则 {#limits}

### 支持哪些格式？ {#rule-formats}

`limit/period` 或 bare `limit`。单位：`s`、`m`、`h`、`d`、`M`、`y`；单字母如 `m` 先当作 `1m`；小数秒会截断为整秒。

### 多条规则？ {#multiple-limits}

可以，例如 `['10/m', '100/h']`，**全部**须通过。

### burst 作用于每条规则？ {#burst}

**否。** 仅**第一条**；burst 路径是内部节奏计算，不是简单多加 N 次。

### 配置 `prefix` 对 `hit()`？ {#prefix-hit}

**不适用。** 手动 key 原样使用。

## 排错 {#troubleshooting}

### 限流不生效先查什么？ {#not-working}

Redis 接线、`#[RateLimit]` 或回退 `limits`、`enabled` 是否为 true。

### 为何比预期更早 429？ {#unexpected-429}

是否多条规则同时生效、身份是否共享、各实例是否同一 Redis、首条是否启用 burst 节奏。

### 事件带什么？ {#events}

`Throttling`（DEBUG）：`key`、`limit`、`period`、`burst`；`Throttled`（INFO）：另含 `count`。

### 如何加 Retry-After？ {#headers}

在异常或响应层自行设置；**本包不自动发**这些头。
