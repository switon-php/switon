---
sidebar_label: Pool 包
description: switon/pool：owner 作用域连接池、guard 借用与自动归还。
---

# Pool 包 {#pool-package}

`switon/pool` 管理**按 owner 划分**的可复用对象池，以基于 guard 的自动归还借用资源。适用于多个调用方需要对同一昂贵资源（数据库客户端、Redis 客户端、mailer、HTTP 引擎等）做容量受限复用，且希望在**不**让业务代码重复借还规则的前提下获得生命周期可观测性。

**关键特性：**

- **Owner 作用域池：** 一个 owner 对象拥有一组池；池存放在以 owner 为键的 `WeakMap` 中。
- **类型变体：** 同一 owner 可保留 `default`、`readonly` 或其它变体，相互独立。
- **Guard 优先借用：** `guard()` 为常规借用路径；返回的 `Switon\Pooling\PoolGuard` 自动释放。
- **手动生命周期：** 借用须跨越更长边界时，仍可使用 `acquire()` / `release()`。
- **运行时播种：** 池尚不存在时 `add()` 可创建池；容量由**首次**播种时的规模决定。
- **生命周期事件：** 借用、归还、忙碌与归还失败路径会发出事件，便于观测。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/core`、`switon/event`、`switon/sync`、`psr/event-dispatcher`

```bash
composer require switon/pool
```

---

## 快速开始 {#quick-start}

注入 `Switon\Pooling\PoolManagerInterface`，由 owner 播种池，并用 `guard()` 借用。

```php
namespace App\Service;

use App\Resource\Connection;
use Switon\Core\Attribute\Autowired;
use Switon\Pooling\PoolManagerInterface;

final class DatabaseClient
{
    #[Autowired] protected PoolManagerInterface $poolManager;

    public function __construct(private readonly string $uri)
    {
        $this->poolManager->add($this, [Connection::class, ['uri' => $this->uri]], 4);
    }

    public function query(string $sql): mixed
    {
        $conn = $this->poolManager->guard($this, timeout: 1.0);

        return $conn->query($sql);
    }
}
```

当 seed 为 `[ClassName, $parameters]` 时，由 `Switon\Core\MakerInterface` 构建首个实例，其余从该实例 **`clone`**。

---

## 核心概念 {#core-concepts}

### Owner 与 Type {#owner-and-type}

池以 `(owner, type)` 为键。

- `owner` 选择池组；传入拥有资源生命周期的对象（服务、客户端、调用点等）。
- `type` 在同一 owner 下选择变体。除非一个 owner 需要多个逻辑池（例如 `default` 与 `readonly`），否则用 `default`。

池存在 `WeakMap` 中；owner 被垃圾回收时，对应池组一并回收。

### 容量与播种 {#capacity}

- `create($owner, $capacity, $type)` 创建指定容量的空池。
- `add($owner, $sample, $size, $type)` 播种池。若池尚不存在，则按首次播种的 `$size` 作为容量创建。
- `add()` 若会使规模超过已有容量则拒绝（`FullException`）。

### 使用 `guard()` 借用 {#guard}

`guard()` 为默认借用路径。

```php
$conn   = $this->poolManager->guard($this, timeout: 1.0);
$result = $conn->query($sql);
```

- `Switon\Pooling\PoolGuard` 通过 `__call()` 将方法调用转发到借出的资源。
- `close()` 与 `release()` 属于 **guard 自身**，不会转发到被包装资源。调用二者可提前归还；否则析构时自动归还。
- 若资源自身定义了 `close()` 或 `release()`，请在 `$guard->resource` 上直接调用。

### 手动借用 {#manual-borrowing}

仅当借出的资源须跨越 **guard 无法跟上的** 更长生命周期边界时，使用 `acquire()` 与 `release()`。

超时规则对 `acquire()` 与 `guard()` 同样适用：

- `null` — 阻塞直至有可用资源；
- `0.0` — 无空闲时立即失败；
- 正数 — 最多等待该秒数，超时抛出 `BusyException`。

### 生命周期事件 {#lifecycle-events}

池管理器发出：

- `Switon\Pooling\Event\ResourceAcquiring` — 取用资源前；
- `Switon\Pooling\Event\ResourceAcquired` — 借用成功；
- `Switon\Pooling\Event\ResourceReturned` — 归还成功；
- `Switon\Pooling\Event\ResourceBusy` — 等待超时、池仍忙碌；
- `Switon\Pooling\Event\ResourceReturnFailed` — 归还失败。

---

## 配置 {#configuration}

池本身几乎不需配置。由 owner 在代码中 `create` / `add` 并播种；每个 owner 的规模通常在该 owner 自身配置中（例如 `Switon\Mail\MailerInterface` 上的 `pool_size`）。

---

## 使用注意 {#keep-in-mind}

- `size()` 报告的是**配置容量**，不是空闲数量。
- `isEmpty()` 表示**当前**没有可立即借用的资源，不表示池中对象总数为零。
- `isEmpty()` 与 `size()` 仅在池已存在时有效；请先 `exists()`。
- `add()` 会将已有池补充到容量上限；若需要全新池，请先 `remove()`。
- guard 仍持有资源时**不要** `remove()` 池；移除后再显式 `close()` 可能失败。析构清理会抑制归还期的错误。

---

## 下一步 {#next}

- **短答：** [FAQ](faq.md#pool-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **池化数据库客户端：** [DB 包](../db/README.md)
- **池化 Redis 客户端：** [Redis 包](../redis/README.md)
- **池化出站 HTTP 引擎：** [HTTP Client 包](../http-client/README.md)
