---
sidebar_label: Sync 包
description: switon/sync：Channel、Mutex、WaitGroup、Stack 四类进程内协调原语。
---

# Sync 包 {#sync-package}

`switon/sync` 提供四种进程内协调原语：FIFO 交接、互斥、完成等待与 LIFO 复用。

**关键特性：**

- **`Channel` 做 FIFO 交接** — 有界 push/pop，带显式 `close` 语义。
- **`Mutex` 保护临界区** — `guard()` 提供短而异常安全的加锁路径。
- **`WaitGroup` 等待完成** — 阻塞直至已知数量的任务结束。
- **`Stack` 做 LIFO 复用** — 固定容量，在「最新优先」合适时使用。
- **运行时之间 API 相同** — 协程与非协程代码调用相同方法，但阻塞行为仍不同。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require switon/sync
```

---

## 快速开始 {#quick-start}

按协调模式选择原语。

```php
use Switon\Sync\Channel;
use Switon\Sync\Mutex;
use Switon\Sync\WaitGroup;

$channel = new Channel(2);
$channel->push('job-1');
$job = $channel->pop();

$mutex = new Mutex();
$lock = $mutex->guard();
try {
    // critical section
} finally {
    $lock->release();
}

$waitGroup = WaitGroup::of(2);
// task A 最终会调用 done()
// task B 最终会调用 done()
$waitGroup->wait();
```

---

## 核心概念 {#core-concepts}

### 原语选型 {#choosing-a-primitive}

- 一条执行流按顺序把工作交给另一条时用 `Channel`。
- 一条执行流须独占临界区时用 `Mutex`。
- 一条执行流须等待已知次数的完成时用 `WaitGroup`。
- 复用应优先使用最新归还项时用 `Stack`。

### `Channel` {#channel}

`Channel` 是有界 FIFO 队列。

- `push()` 添加一项。
- `pop()` 移除最旧一项。
- `close()` 停止后续 `push()`。
- `close()` 后已排队项仍可读取。
- `capacity` 至少为 `1`；`0` 会使首次 `push()` 因 `ChannelFullException` 失败。

运行时行为不同：

- 协程模式下，对空队列 `pop()` 可等待。
- 非协程模式下，对空队列 `pop()` 快速失败。
- 协程模式下，`pop($timeout)` 超时返回 `false`。

常用检查：`isEmpty()`、`isFull()`、`length()`、`capacity()`、`isClosed()`。

### `Mutex` {#mutex}

`Mutex` 保护临界区。常规路径为 `guard()`。

- `guard()` 返回 `Switon\Sync\Lock`。
- `release()` 结束锁定。
- 锁在析构时也会释放。
- 协程模式下，同一协程可重入该 mutex。
- 非协程模式下，第二次 `lock()` / `guard()` 立即失败。

仅在需要直接锁 API 时使用 `unlock()`。多数代码应把 `Lock` 放在局部变量中，并在 `finally` 里 `release()`。

### `WaitGroup` {#waitgroup}

`WaitGroup` 阻塞直至计数回到零。

- `WaitGroup::of($count)` 以正计数开始。
- `add()` 提高计数，且增量至少为 `1`。
- `done()` 降低计数。
- `wait()` 阻塞直至计数为零。
- 同一实例上，同一时间只有一个协程可 `wait()`。
- 计数已为 zero 时调用 `done()` 会抛出 `MisuseException`。

常用检查：`count()`。

### `Stack` {#stack}

`Stack` 是有界 LIFO 容器。

- `push()` 将最新项压在栈顶。
- `pop()` 先返回最新项。
- 协程模式下 `pop($timeout)` 可等待，超时返回 `false`。

在「最新优先」复用合适时使用 `Stack`。若需要借还生命周期，请改用 [Pool 包](../pool/README.md)。

---

## 使用注意 {#keep-in-mind}

- `Channel` 用于交接，**不**表示所有权。
- `Mutex` 用于**短**临界区，不用于长时间任务。
- `WaitGroup` 只协调完成，**不**传递结果。
- `Stack` **不是**资源池。
- `Sync` **没有** `switon.yml` 配置面。
- 阻塞行为仍依赖协程运行时支持。

---

## 下一步 {#next}

- **快速答疑：** [FAQ](faq.md#sync-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **借还池化：** [Pool 包](../pool/README.md)
