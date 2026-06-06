---
sidebar_label: Pool FAQ
description: Pool：owner/type、guard 与 BusyException、WeakMap。
---

# Pool FAQ {#pool-faq}

**接线、借用与池状态相关的短答。**

> **阅读前提：** [Pool 包](README.md)

## 入门 {#getting-started}

### 需要单独配置文件吗？ {#config-file}

不需要。池几乎不依赖配置文件；由 owner 在代码中调用 `create()` 或 `add()`。

### `owner` 和 `type` 是什么？ {#owner-type}

`owner` 选择池组。`type` 在该 owner 下选择变体。见 [Owner 与 Type](README.md#owner-and-type)。

### `guard()` 返回什么？ {#guard-return}

`Switon\Pooling\PoolGuard`。它将方法调用转发到借出的资源，并在销毁时将其归还池中。

### 何时用手动 acquire/release？ {#guard-vs-manual}

默认用 `guard()`。仅当借出的对象须跨越 **guard 的自动归还无法覆盖** 的更长生命周期边界时，再用手动 `acquire()` / `release()`。

## 状态 {#state}

### `size()` 表示什么？ {#size-meaning}

**配置容量**，不是空闲个数。

### 在 `create()` 之前调用 `add()`？ {#add-before-create}

池会自动创建，并以**首次** `add()` 的规模作为容量。

### guard 仍存在时移除了池？ {#remove-while-borrowed}

显式 `close()` 在移除后可能抛出 `PoolNotFoundException`。析构清理会抑制归还期的错误，owner 被回收后池组也可能直接消失。

## 排错 {#troubleshooting}

### `BusyException`？ {#busy-exception}

常见原因：池容量偏小、guard 持有过久，或超时过短。

### `isEmpty()` 抛异常？ {#isempty-throws}

池尚不存在时会抛 `PoolNotFoundException`。请先 `exists()`。

### 池自己消失？ {#weakmap-owner}

池通过 `WeakMap` 归属 owner；owner 被垃圾回收时，池组一并回收。
