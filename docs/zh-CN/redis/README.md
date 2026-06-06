---
sidebar_label: Redis 包
description: switon/redis：池化 ClientInterface、getTransient 与 redis/sentinel/cluster URI。
---

# Redis 包 {#redis-package}

`switon/redis` 为 Switon 应用提供**池化** Redis 客户端，并为需要绑定连接的流程提供显式 **transient** 路径。适用于多数调用短而无状态的场景；事务、扫描与阻塞命令则选用专用连接。本包**不**把 Redis 变成缓存抽象，也**不**在应用存在多个 Redis 目标时替你选定默认命名客户端。

**关键特性：**

- **池化模式：** 常规 Redis 调用借出连接并在每次调用后自动归还。
- **Transient 逃生舱：** `getTransient()` 保留一条专用连接，用于事务、与游标扫描及阻塞类流程。
- **URI 驱动连接器：** `Switon\Redis\Connection` 接受 `redis://`、`rediss://`、`sentinel://` 与 `cluster://`。
- **生命周期事件：** 可使用 `RedisConnecting`、`RedisConnected`、`RedisCalling`、`RedisCalled` 与 `RedisClose`。
- **CLI 诊断：** `redis:ping`、`redis:info`、`redis:server`，用于连通性与服务器检查。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`ext-redis`、`switon/core`、`switon/di`、`switon/event`、`switon/pool`、`psr/event-dispatcher`

```bash
composer require switon/redis
```

---

## 快速开始 {#quick-start}

注入 `Switon\Redis\ClientInterface`，然后使用常规 Redis 命令。

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Redis\ClientInterface;

final class SessionStore
{
    #[Autowired] protected ClientInterface $redis;

    public function save(int $userId, array $payload): void
    {
        $this->redis->set("user:{$userId}", json_encode($payload), 3600);
    }
}
```

当某工作流需要连接状态时，使用 `getTransient()`。

```php
use Switon\Redis\Client;

/** @var Client $redis */
$redis = $this->redis->getTransient();
$redis->watch('wallet:1');
$redis->multi();
$redis->set('wallet:1:balance', '900');
$redis->set('wallet:1:updated_at', (string) time());
$redis->exec();
```

---

## 核心概念 {#core-concepts}

### 池化模式 {#pooled-mode}

常规 Redis 命令从池中借出一条连接，每次调用结束后归还。池化客户端会拒收绑定连接的命令（`watch`、`unwatch`、`multi`、`pipeline`、`select`、`scan`、`sScan`、`hScan`、`zScan`），抛出 `CallInPoolException`；同时拒收返回对象的命令，迫使需要状态的工作流显式使用 `getTransient()`。

### Transient 客户端 {#transient-client}

`getTransient()` 返回绑定到一条专用池化连接的克隆。用于事务、游标扫描，以及 `subscribe()`、`psubscribe()`、`monitor()` 等阻塞命令。

### URI 示例 {#connection-uri}

每个 Redis 目标使用一条 URI：

```text
redis://127.0.0.1:6379/0?timeout=2&heartbeat=60
rediss://127.0.0.1:6380/0?auth=redis-secret&timeout=2
sentinel://10.0.0.1:26379,10.0.0.2:26379/mymaster?auth=redis-secret&sentinel_auth=sentinel-secret&db=3
cluster://10.0.0.1:7000,10.0.0.2:7001?auth=redis-secret&timeout=2&persistent=1
```

常见查询项：`auth`、`sentinel_auth`、`timeout`、`db`、`persistent`、`heartbeat`。池化客户端还会读取 `pool_timeout` 与 `pool_size`。`read_timeout` 适用于单节点、TLS 与 Sentinel 主连接；**Cluster 忽略** `read_timeout`。`cluster://` 还**忽略** `db=`。

---

## 使用注意 {#keep-in-mind}

- 有状态命令（`multi`、`watch`、`pipeline`、`select`、`scan` 系列）与阻塞命令（`subscribe`、`psubscribe`、`monitor`）**仅**能在 transient 客户端上运行。
- `heartbeat=0` **并不**关闭检查；后续调用仍会校验既有连接。
- `persistent=1` 仅在运行时启用协程支持时生效。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#redis-faq)
- **连接模型与取舍：** [设计说明](design.md#design-rationale)
- **池化机制：** [Pool 包](../pool/README.md)
- **Redis 作队列传输：** [Queue 包](../queue/README.md)
