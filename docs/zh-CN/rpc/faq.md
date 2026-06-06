---
sidebar_label: RPC FAQ
description: RPC：首次 call、methods 别名、超时与 rpc:daemon。
---

# RPC FAQ {#rpc-faq}

**RPC 接线与运行相关的短答。**

> **阅读前提：** [RPC 包](README.md)

## 入门 {#getting-started}

### 第一次 RPC 怎么跑通？ {#first-call}

注入 `Switon\Rpc\RpcInterface`，调用 `call()`，并在 Worker 侧执行 `bash bin/console rpc:daemon`。

### 方法必须是 `ClassName::method`？ {#method-format}

不必；可在 `methods` 里配置逻辑名。

### 参数必须按位置传？ {#params}

不必；具名 map 即可。

### 回复如何回到正确的调用方？ {#reply-matching}

每次 `call()` 使用 `correlation_id` 与**进程级**回复队列；id 在进程内单调递增，回复可乱序到达。

## 配置 {#configuration}

### 在哪里配置？ {#where-config}

在 `switon.yml` 配置 `Switon\Rpc\RpcInterface`。

### 默认值？ {#defaults}

`queue` = `rpc`，`methods` = 空，`concurrency` = `8192`，超时 = **3** 秒。

### 如何查看全部选项？ {#discover-options}

`bash bin/console config:options 'Switon\Rpc\RpcInterface'`

### `concurrency` 管什么？ {#concurrency}

`daemon()` 提交 handler 时使用的 **executor 并发**。

## 错误 {#security-and-errors}

### 何时 `RpcRemoteException`？ {#remote-exception}

Worker 返回合法 `error` 回复时。

### 何时 `RpcTimeoutException`？ {#timeout}

在超时前未收到匹配回复。

### 回复体必须含什么？ {#reply-shape}

`result` **或** `error`。

`error` 序列化为 `{ class, message, code }`，调用方将非整型 `code` 视为 `0`。

## 运维 {#operations}

### 如何启动 Worker？ {#start-worker}

`bash bin/console rpc:daemon`（阻塞读队列，handler 交 executor）。

### RPC 会取代 Queue 或 HTTP API？ {#queue-or-http}

**不会。** RPC 是面向 PHP 调用方/Worker 的**队列请求-回复**；单向后台作业仍用 Queue，对外 HTTP 仍用 HTTP。
