---
description: 任务优先路线：在更广协议设计之前，让已验证的服务边界或持久化 worker 路径等待单次队列回复。
---

# 添加基于队列的请求/回复 {#add-queue-backed-request-reply}

当某条已验证的服务边界或持久化后台路径现在必须等待 worker 恰好一次回复，且无需另建 HTTP API 时，使用本指南。

## 选对请求/回复边界 {#reply-boundary}

从与调用方真实需求相匹配的边界开始。

| 边界 | 最适合 | 注意 |
|---|---|---|
| [Executor](../executor/README.md) | 调用方希望在当前运行时执行 runner，无需持久化传输 | 强于共享 `RunnerInterface` 执行，不用于队列支撑的回复契约 |
| [Queue](../queue/README.md) | 调用方可在入队后继续，此刻不需要回复 | 当持久化单向投递比立即完成更重要时，是最佳默认 |
| [RPC](../rpc/README.md) | 调用方必须等待 worker 恰好一次回复 | 底层仍用队列传输，但增加超时、回复匹配与远程失败语义 |
| [HTTP](../http/README.md) | 调用方是浏览器、外部系统或其他语言栈 | 当协议边界不再是队列原生的 PHP 对 PHP 通信时更合适 |
| [Redis](../redis/README.md) | 回复流量需要独立客户端、数据库或连接池策略 | 仅当后端客户端边界才是真问题时再调优 |

一种常见应用形态：

- **应用服务** 调用 `RpcInterface::call()`
- **Queue** 将请求信封带到 worker 侧，将回复信封带回调用方侧
- **`rpc:daemon`** 为该队列运行长驻的接收/回复循环
- **worker 方法** 在该 daemon 路径内运行，返回数据或抛出，调用方看到一次结果或一次类型化的 RPC 失败

换言之：调用方 push 一个请求信封，`rpc:daemon` pull 并调用目标方法，然后 daemon 写入一个回复信封，调用方等待直到成功、远程失败或超时。

## 推荐构建顺序 {#build-order}

### 1. 在服务边界已正确之后再开始 {#build-service}

RPC 应包裹你已信任的作业。
若业务规则仍直接写在控制器、脚本或仅队列的处理胶水代码里，先修好该边界。

### 2. 仅当调用方确实必须等待时才选 RPC {#build-choice}

若入队后继续仍可接受，留在[添加持久化后台工作](add-durable-background-work.md)。
RPC 是更强的契约，因为调用方现在依赖一次回复或一次超时。

### 3. 从一个直接的 `ClassName::method` 目标开始 {#build-direct-method}

第一条回复路径应保持显而易见。
在引入逻辑 `methods` 别名之前，使用一个由容器解析的类与一个 public 方法。

### 4. 保持一个队列与一条可见的 daemon 路径 {#build-daemon}

除非能用一句话解释不同名称，否则从默认 `rpc` 队列开始。
运行一条可见的 `rpc:daemon` 循环，便于追踪第一条调用方与 worker 路径。

### 5. 分别验证成功、远程失败与超时 {#build-verify}

在增加更多方法之前，确认三种结果：
- 一次正常调用返回预期结果
- worker 侧一次异常变为 `Switon\Rpc\Exception\RpcRemoteException`
- 缺少 worker 或队列不匹配产生 `Switon\Rpc\Exception\RpcTimeoutException`

### 6. 仅当调用方稳定性重要时才加逻辑名 {#build-alias}

直接方法字符串是最清晰的首个设置。
当许多调用方在 handler 重构后仍应保持稳定时，再添加 `methods` 映射。

### 7. 在更广协议或平台设计之前停下 {#build-stop}

第一个 RPC 成果**不需要**流式、跨语言契约或服务发现。
在扩大系统之前，先让一条基于队列的回复路径可信。

## 最小接线 {#minimal-wiring}

仅用短代码展示等待中的调用方、基于队列的 worker 循环与回复结果之间的接缝。

下文中的 `BillingGateway`、`InvoiceService`、`billing.invoice.show` 等应用层名称仅为示例。稳定部分是 Switon 的 `RpcInterface` / 队列 / Executor 边界。

### 调用方通过 `RpcInterface` 等待 {#wiring-caller}

在应用代码中保持等待边界显式：

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Rpc\RpcInterface;

final class BillingGateway
{
    #[Autowired] protected RpcInterface $rpc;

    public function fetchInvoice(int $invoiceId): array
    {
        return $this->rpc->call(InvoiceService::class . '::show', ['invoiceId' => $invoiceId], 3);
    }
}
```

### Worker 处理方仍是普通服务方法 {#wiring-handler}

应用处理方**不需要**因为调用方现在等待回复就变成入队的 `RunnerInterface` 作业：

```php
namespace App\Service;

final class InvoiceService
{
    public function show(int $invoiceId): array
    {
        return ['id' => $invoiceId, 'status' => 'paid'];
    }
}
```

### 保持 RPC 配置精简 {#wiring-config}

在 `switon.yml` 中从队列名与 daemon 提交并发开始：

```yaml
# switon.yml
Switon\Rpc\RpcInterface:
  queue: rpc
  concurrency: 256
```

仅当你希望稳定逻辑名（如 `billing.invoice.show`）而不是在每个调用点暴露 `App\Service\InvoiceService::show` 时，再添加 `methods`。

### 运行一条可见的 daemon {#wiring-daemon}

在应用根目录，让第一条 worker 路径显而易见：

```bash
bash bin/console rpc:daemon
```

若项目未提供 `bin/console`，按 [CLI Quick Start](../cli/README.md#fallback-entry) 中的备用入口形态操作。

第一条成功线很小：

- 一个调用方通过 `RpcInterface` 等待一个结果
- 一条 daemon 循环从配置的 RPC 队列接收请求
- 一次 handler 返回变成结果；worker 侧失败表现为 `Switon\Rpc\Exception\RpcRemoteException`、`Switon\Rpc\Exception\RpcTimeoutException` 或 `Switon\Rpc\Exception\RpcProtocolException`

## 初期应避免 {#pitfalls}

有意让第一条请求/回复路径保持狭窄。

过早避免这些：

- **在调用方入队后即可继续时使用 RPC** — 单向投递留在[添加持久化后台工作](add-durable-background-work.md)
- **在 daemon 读取同一队列之前启动调用方** — 没有 worker 意味着调用方最终会超时
- **过早用别名隐藏第一条契约** — 在添加 `methods` 之前从一个直接的 `ClassName::method` 目标开始
- **把超时当作 worker 从未运行的证明** — `Switon\Rpc\Exception\RpcTimeoutException` 只证明调用方在时限内未收到匹配的回复
- **把 RPC 当作公开 API 面** — 当浏览器、外部服务或其他语言栈才是真边界时，改用 [HTTP](../http/README.md)
- **在一条回复路径可信之前就设计流式或服务发现** — 让第一条基于队列的回复流可读、可测

初学者在完成第一条回复路径后应有这样的感受：

> 我知道一条已验证的服务如何变成基于队列的请求/回复，而无需把第一个 worker 流变成更大的协议平台。

## 深入阅读 {#go-deeper}

- **在调用方等待之前，从单向持久化交接起步：** [添加持久化后台工作](add-durable-background-work.md) · [Queue](../queue/README.md)
- **深入回复协议、别名与失败面：** [RPC](../rpc/README.md)
- **理解底下的传输与执行层：** [Queue](../queue/README.md) · [Executor](../executor/README.md)
- **改为面向浏览器或公开的请求/响应：** [HTTP](../http/README.md)
- **调优 Redis 客户端、连接池或键空间策略：** [Redis](../redis/README.md) · [DI](../di/README.md#quick-start)
