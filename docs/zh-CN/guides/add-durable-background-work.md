---
description: 任务优先路线：在请求/回复设计或 worker 平台设计之前，将已验证的服务、命令或周期任务交给持久化队列。
---

# 添加持久化后台工作 {#add-durable-background-work}

当某条已有服务、命令或周期任务需要把作业交给持久化队列传输，使生产者与 worker 不再共享同一进程或同一成功窗口时，使用本指南。

## 选对持久化工作边界 {#work-boundary}

从与应用实际需要的投递保证相匹配的边界开始。

| 边界 | 最适合 | 注意 |
|---|---|---|
| [Executor](../executor/README.md) | 调用方希望在当前运行时轻量执行 runner，而非持久化传输 | 强于进程内执行与生命周期钩子，不用于在 worker/进程失败后仍存活 |
| [Queue](../queue/README.md) | 单向作业应跨进程边界存活，并由 worker 循环稍后消费 | 当传输持久化比立即完成更重要时，是最佳默认 |
| [RPC](../rpc/README.md) | 调用方必须等待 worker 的一次回复 | 建立在队列传输之上，但增加更严格的回复契约与超时面 |
| [Schedule](../schedule/README.md) | 时间规则决定**何时**开始，但实际交接仍可能需要持久化 | 当周期生产者应入队而非内联完成时，与队列配对 |
| [Redis](../redis/README.md) | 队列流量需要独立客户端、数据库或连接池策略 | 仅当后端客户端边界才是真问题时再调优 |

一种常见应用形态：

- **HTTP / CLI / Schedule** 产生一个小的作业单元
- **Queue** 持久存储传输信封
- **Executor** 运行解析后的 `RunnerInterface` 作业
- **RPC** 仅当生产者必须阻塞等待响应时

## 推荐构建顺序 {#build-order}

### 1. 在生产者路径已正确之后再开始 {#build-producer}

入队应包裹你已信任的业务逻辑。
若作业仍直接写在控制器、仅 shell 脚本或临时回调里，先修好该边界。

### 2. 选定一个逻辑队列与一个 runner 边界 {#build-queue-name}

从一个能用一句话说清的队列名开始。
此处的「队列名」指生产者 push 与 worker pull 的逻辑队列标识，而非原始 Redis 键布局。
一个逻辑队列应对应一个 runner 类；在扩大吞吐或拆分路由之前，先让第一条生产者/消费者路径显而易见。

### 3. 入队传输数据，而非活的服务 {#build-envelope}

生产者应推送 worker 稍后能理解的小载荷。
在容器中解析服务、在 worker 中执行，不要塞进序列化对象或临时闭包。
这样队列载荷小而稳定、可安全反序列化，而不是试图序列化活的服务实例、连接或运行时状态。

### 4. 让延迟投递保持显式 {#build-delay}

若使用延迟消息，记住 `pull()` **不会**自动迁移它们。
在拥有延迟迁移的 worker 循环中调用 `migrateDue()`，或在第一条队列路径中先不用延迟。

### 5. 验证一次入队、一次消费与一次统计读取 {#build-verify}

在增加更多队列之前，确认三件事：
- 生产者能成功 push 一个信封
- 一次 worker 通行能对预期队列 `pull()` 并 `consume()`
- `stats('<queue>')`（见 [Queue stats](../queue/README.md#stats)）或面向运维的队列探针表明你在看正确的逻辑队列

### 6. 在扩展之前先加幂等与最小失败可见性 {#build-idempotency}

第一条持久化路径需要尽早养成两个习惯：让处理逻辑可安全重放，并记录足够信息以看到一次成功或一次失败。
在设计重试、worker 集群或更丰富指标之前先做这些。

### 7. 在回复协议或 worker 平台设计之前停下 {#build-stop}

第一个队列成果**不需要** RPC 别名、supervisor 设计或死信架构。
在扩大系统之前，先让一条持久化路径可信。

## 最小接线 {#minimal-wiring}

仅用短代码展示生产者、队列传输与入队 runner 之间的接缝。

下文中的 `WelcomeEmailServiceInterface`、`UserRegistrationService`、`SendWelcomeEmailJob` 等应用层名称仅为示例。稳定部分是 Switon 队列与 runner 边界。

### 共享应用服务保持不变 {#wiring-service}

将业务动作保留在一个应用边界之后：

```php
namespace App\Service;

interface WelcomeEmailServiceInterface
{
    public function sendForUser(int $userId): void;
}
```

### 生产者交接一个信封 {#wiring-producer}

注册成功时，推送一个传输载荷，而不是内联发信：

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Queue\QueueEnvelope;
use Switon\Queue\QueueInterface;

final class UserRegistrationService
{
    #[Autowired] protected QueueInterface $queue;

    public function register(int $userId): void
    {
        // persist the user first...
        $this->queue->push('emails', QueueEnvelope::of(['userId' => $userId]));
    }
}
```

### 一个入队 runner 拥有 worker 边界 {#wiring-job}

用 [Queue](../queue/README.md) 将逻辑队列映射到一个 runner 类：

```php
namespace App\Job;

use App\Service\WelcomeEmailServiceInterface;
use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;
use Switon\Queue\QueueEnvelope;

#[Queued('emails')]
final class SendWelcomeEmailJob implements RunnerInterface
{
    #[Autowired] protected WelcomeEmailServiceInterface $mailer;
    #[Autowired] protected LoggerInterface $logger;

    public function run(mixed $payload): mixed
    {
        /** @var QueueEnvelope $envelope */
        $envelope = $payload;
        $userId = (int) ($envelope->body['userId'] ?? 0);

        $this->mailer->sendForUser($userId);
        $this->logger->info('Sent welcome email', ['userId' => $userId]);

        return null;
    }
}
```

职责划分保持清晰：

- 生产者决定**应用何时应入队作业**
- 队列传输决定**信封如何存储与拉取**
- runner 决定**worker 如何执行一个入队单元**

### 队列传输与发现保持显式 {#wiring-config}

在 `switon.yml` 中保持第一条队列路径精简：

```yaml
# switon.yml
Switon\Redis\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: redis://127.0.0.1:6379/0

Switon\Queue\QueueInterface:
  prefix: 'queue:'
  dueEvery: 1

Switon\Queue\JobDiscoveryInterface:
  paths:
    '@app/Job/*Job.php': 'App\Job\*Job'
```

先使用默认 Redis 客户端。`QueueInterface` 由 Redis 支撑，因此该客户端绑定是最小可用路径的一部分，而非可选优化层。
仅当队列、缓存或 session 流量确实需要不同后端策略时，再拆分命名客户端。

### 运行一次显式 worker 通行 {#wiring-worker}

当一次 worker 通行在常规应用代码或一个小型运维命令中可见时，第一条持久化路径更容易信任：

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Queue\QueueInterface;

final class EmailQueueProbe
{
    #[Autowired] protected QueueInterface $queue;

    public function processOnce(): void
    {
        $this->queue->migrateDue('emails');
        $envelope = $this->queue->pull('emails', 1);

        if ($envelope !== null) {
            $this->queue->consume($envelope);
        }
    }
}
```

若尚未使用延迟，`migrateDue()` 无害，但不是就绪队列能工作的原因。
第一条成功线很小：

- 一个生产者不再要求 worker 内联完成
- 一个逻辑队列映射到一个 runner 类
- 一次显式 worker 通行可以 pull、consume 并检查队列状态

## 初期应避免 {#pitfalls}

有意让第一条持久化路径保持狭窄。

过早避免这些：

- **在生产者边界清晰之前就入队** — 先证明服务、命令或周期生产者
- **把入队成功当作作业可发现的证明** — 失败仍可能在 `consume()` 时出现
- **指望 `#[Queued(..., concurrency: N)]` 替你管理 worker** — 该元数据本身不会变成内置 supervisor 或传输节流
- **假设延迟作业会自动出现在 `pull()` 中** — 设计包含延迟时调用 `migrateDue()`
- **在调用方其实需要一次回复时使用队列** — 请求/回复语义改用 [RPC](../rpc/README.md)
- **在一条 worker 路径可信之前就设计重试、死信路由或 exactly-once** — 让第一个队列成果可读、可测

初学者在完成第一条持久化路径后应有这样的感受：

> 我知道一条已验证的应用路径如何变成持久化后台工作，而无需把第一个队列变成整套平台。

## 深入阅读 {#go-deeper}

- **在周期生产者路径之后衔接：** [添加周期性任务](add-recurring-work.md#add-recurring-work) · [定时清理](../tutorial/timed-cleanup.md#timed-cleanup)
- **用持久化 worker 发欢迎、重置或回执类邮件，而非泛泛的作业设计：** [发送事务性邮件](send-transactional-email.md#send-transactional-email)
- **深入传输、发现与 worker 拓扑：** [Queue](../queue/README.md)
- **直接管理 runner 执行与生命周期钩子：** [Executor](../executor/README.md)
- **调优 Redis 客户端、连接池或键空间策略：** [Redis](../redis/README.md) · [DI](../di/README.md#quick-start)
- **在同一上线形态中运行持久化 worker 与 HTTP、调度器进程：** [将应用部署到生产环境](deploy-an-app-to-production.md#deploy-an-app-to-production)
- **将单向投递变为请求/回复：** [添加基于队列的请求/回复](add-queue-backed-request-reply.md#add-queue-backed-request-reply) · [RPC](../rpc/README.md)
- **把时间规则留在生产者边界：** [Schedule](../schedule/README.md)
- **增加轻量失败可见性：** [Log](../log/README.md#quick-start)
