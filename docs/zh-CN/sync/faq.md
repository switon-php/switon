---
sidebar_label: Sync FAQ
description: Sync：选型、协程 vs 非协程、Channel 关闭与 WaitGroup 限制。
---

# Sync FAQ {#sync-faq}

**协调原语相关的短答。**

> **阅读前提：** [Sync 包](README.md)

## 入门 {#getting-started}

### 该用哪个原语？ {#which-primitive}

有序交接 → `Channel`；互斥 → `Mutex`；等完成 → `WaitGroup`；最新优先复用 → `Stack`。

### 用 Stack 做连接池？ {#stack-for-pooling}

仅当你只需要裸 LIFO 容器。借还要用 [Pool 包](../pool/README.md)。

## 运行时 {#runtime-behavior}

### 为何 `pop()` 在协程/非协程下不同？ {#pop-runtime-difference}

协程可挂起等待；非协程不能阻塞，空读**快速失败**。

### 非协程下 `WaitGroup::wait()` 为何失败？ {#wait-non-coroutine}

运行时无法以相同方式阻塞并恢复该路径。若计数未归零，`wait()` 抛出 `Switon\Sync\Exception\WaitTimeoutException`。

### Mutex 可重入吗？ {#mutex-reentrant}

**仅**协程模式下**同一协程**可重入。非协程下第二次 `lock`/`guard` 立即失败。

### `Channel::close()` 之后怎么用？ {#channel-close}

继续 `pop` 排空，不再 `push`。关闭且排空后 `pop()` 抛出 `Switon\Sync\Exception\ChannelClosedException`；重复 `close()` 也抛出 `ChannelClosedException`。

### `add(0)` 或 `done()` 过多？ {#waitgroup-bounds}

`add(0)` → `InvalidArgumentException`；计数已为 0 再 `done()` → `MisuseException`。

## 排错 {#troubleshooting}

### `pop($timeout)` 返回 `false`？ {#pop-false}

可能是超时；若业务载荷本身可能是 `false`，入队前请包装（例如数组包一层）。

### 为何只能一个协程 `wait()`？ {#single-waiter}

当前实现限制；多路等待请用多个 `WaitGroup`。已有等待进行时再次 `wait()` → `Switon\Sync\Exception\WaitGroupConcurrentException`。
