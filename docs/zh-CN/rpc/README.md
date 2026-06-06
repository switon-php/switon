---
sidebar_label: RPC 包
description: switon/rpc：队列一次请求一次回复、call()、rpc:daemon 与 methods 别名。
---

# RPC 包 {#rpc-package}

`switon/rpc` 发送一条基于队列的请求并等待一条回复。适用于在现有队列传输上做**同步** PHP 对 PHP 的请求-回复。流式、多回复与公开跨语言 RPC **不在**范围内。

**关键特性：**

- **单次请求-回复：** `call()` 返回一个结果，远端错误抛出 `RpcRemoteException`，超时抛出 `RpcTimeoutException`。
- **逻辑方法名：** `methods` 将稳定名称映射为 `ClassName::method` 处理器。
- **守护 Worker：** `rpc:daemon` 从配置的队列拉取请求，并通过 executor 提交 handler 工作。
- **协议边界事件：** `RpcCalling`、`RpcReceived` 与 `RpcDaemonFailed` 追踪发送、接收与守护进程失败。
- **严格回复协议：** 畸形回复抛出 `RpcProtocolException`。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/core`、`switon/queue`、`switon/executor`、`switon/invoker`

RPC 使用 `switon/queue` 作为传输。

```bash
composer require switon/rpc
```

---

## 快速开始 {#quick-start}

注入 `Switon\Rpc\RpcInterface`，调用一个 handler，并在另一端运行 worker。

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Rpc\RpcInterface;

final class UserGateway
{
    #[Autowired] protected RpcInterface $rpc;

    public function profile(int $userId): array
    {
        return $this->rpc->call(
            UserProfileService::class . '::profile',
            ['userId' => $userId],
        );
    }
}

final class UserProfileService
{
    public function profile(int $userId): array
    {
        return ['id' => $userId];
    }
}
```

启动一个 worker：

```bash
bash bin/console rpc:daemon
```

---

## 核心概念 {#core-concepts}

### Call {#call}

`call($method, $params, $timeout = 3)` 发送一条请求信封，等待匹配的回复，并返回 `result`。`timeout` 单位为秒且须为正数。

- `result` 作为返回值；
- 远端 `error` 变为 `RpcRemoteException`；
- 畸形回复变为 `RpcProtocolException`；
- 超时内无匹配回复变为 `RpcTimeoutException`。

### 方法解析 {#method-resolution}

直接调用使用 `ClassName::method`。`methods` 映射将逻辑名转为该 handler 字符串。Worker 经容器与 invoker 解析最终 handler。

### 守护进程 {#daemon}

`rpc:daemon` 运行长驻拉取循环：

- 从配置的队列拉取一条请求信封；
- 派发 `RpcReceived`；
- 以 `concurrency` 将 handler 工作提交给 executor；
- 使用相同 `correlation_id` 发送回复或错误回复。

每个调用方进程拥有名为 `.rpc.reply.{uuid}` 的回复队列（点前缀，故跳过队列统计）。`correlation_id` 为进程内单调字符串；回复可能乱序到达，`call()` 按 id 缓冲。

### 回复约定 {#reply-contract}

回复体须包含 `result` **或** `error`。`error` 序列化为 `{ class, message, code }`，调用方将非整型 `code` 视为 `0`。

---

## 配置 {#configuration}

多数应用只需三项：

```yaml
Switon\Rpc\RpcInterface:
  queue: rpc
  concurrency: 8192
  methods:
    user.profile: App\Service\UserProfileService::profile
```

- `queue` — 请求队列名；
- `concurrency` — `daemon()` 使用的 executor 并发；
- `methods` — 逻辑名到 handler 字符串的映射；
- 回复处理使用绑定到 `Switon\Queue\QueueInterface` 的传输。

### 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Rpc\RpcInterface'
```

---

## 使用注意 {#keep-in-mind}

- `call()` 是同步的，在收到回复或超时前会阻塞。
- 基于**队列**，不是 HTTP；两侧均为 PHP 并共享类型。
- `rpc:daemon` 为常规 worker 入口；回复传输复用绑定到 `Switon\Queue\QueueInterface` 的队列。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#rpc-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **队列传输：** [Queue 包](../queue/README.md)
- **Executor 行为：** [Executor 包](../executor/README.md)
- **端到端接线：** [添加基于队列的请求/回复](../guides/add-queue-backed-request-reply.md#add-queue-backed-request-reply)
