---
sidebar_label: Queue 包
description: switon/queue：Redis 队列、QueueEnvelope、#[Queued] 与 migrateDue。
---

# Queue 包 {#queue-package}

`switon/queue` 是基于 **Redis** 的作业队列。任务是实现 `Switon\Core\RunnerInterface` 的类，并通过 `#[Queued]` 绑定到单一队列名。它适用于单一 PHP 应用内的异步、**至少一次**作业；重试策略、死信策略和 worker 编排都由应用负责。

**关键特性：**

- **信封传输：** `QueueEnvelope` 携带 body、headers、队列名和消息元数据。
- **延迟投递：** `push()` 立即入队或写入延迟有序集合；`migrateDue()` 让它变为就绪。
- **任务发现：** `#[Queued('queue-name', concurrency: N)]` 将一个 Runner 类绑定到一个队列。
- **Worker 命令：** `queue:stats`、`queue:push`、`queue:run`、`queue:work`、`queue:migrate-due`。
- **无分片路由：** 一个队列名精确对应一个 Runner 定义。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/core`、`switon/redis`、`switon/executor`、`switon/composer-extra`、`psr/event-dispatcher`

```bash
composer require switon/queue
```

---

## 快速开始 {#quick-start}

定义一个 Runner，推送一个信封，再交给 CLI worker 消费。

```php
namespace App\Job;

use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;
use Switon\Queue\QueueEnvelope;

#[Queued('emails', concurrency: 2)]
final class SendWelcomeEmailJob implements RunnerInterface
{
    /**
     * @param QueueEnvelope $payload
     */
    public function run(mixed $payload): mixed
    {
        // send email with $payload->body['userId']
        return null;
    }
}
```

```php
use Switon\Core\Attribute\Autowired;
use Switon\Queue\QueueEnvelope;
use Switon\Queue\QueueInterface;

final class UserService
{
    #[Autowired] protected QueueInterface $queue;

    public function register(int $userId): void
    {
        $this->queue->push('emails', QueueEnvelope::of(['userId' => $userId]));
    }
}
```

```bash
bash bin/console queue:work
```

---

## 核心概念 {#core-concepts}

### 队列信封 {#queue-envelope}

`Switon\Queue\QueueEnvelope` 是传输单元，携带 `message_id`、`queue`、`timestamp`、可选协议字段（`type`、`correlation_id`、`reply_to`、`expiration`）、`body` 与 `headers`。

`QueueEnvelope::of($body, $headers)` 是载荷侧辅助构造；需要协议字段时直接在信封上设置。

### 入队与拉取 {#push-pull}

`push($queue, $envelope, $delay)` 将一条信封存入 Redis：缺 `message_id` 就生成新 UUID，缺 `timestamp` 就使用当前时间。`delay = 0` 写入就绪列表；`delay > 0` 写入延迟有序集合。

`pull($queues, $timeout)` 读取一条就绪信封。`$queues` 为空时回退到已发现的队列名。超时会传给 Redis `BRPOP`；`0` 表示阻塞，直到有任务到达。`pull()` **不**迁移延迟消息。

每次操作后分别触发 `Switon\Queue\Event\QueuePushed` 与 `Switon\Queue\Event\QueuePulled`。

### 延迟迁移 {#delayed-migration}

延迟消息只有在某个循环调用 `migrateDue($queues)` 后才会变为就绪。该调用按批将到期消息移出，并按每个队列、每个 PHP 进程的 `dueEvery` 设置节流。自带的 CLI 循环是 `queue:migrate-due`；`pull()` 本身不做迁移。

### 消费 {#consume}

`consume($envelope)` 根据 `$envelope->queue` 查找 Runner 类，并把执行委托给 executor。

- 未映射队列会在运行 `consume()` 时失败（**不是**在 `push()` 时失败）。
- `concurrency = 1` 走直接 `invoke()`；`concurrency > 1` 提交给 executor。
- `queue:work` 可接受单个队列名、逗号分隔列表，或不传参（使用已发现队列）。

### 发现 {#discovery}

`Switon\Queue\JobDiscovery` 从以下来源构建队列定义：

- 应用扫描路径 `@app/Job/*Job.php` 和 `@app/Areas/*/Job/*Job.php`
- Composer `extra.switon.jobs`

每个被发现的类须实现 `Switon\Core\RunnerInterface` 并带有 `#[Queued('queue-name')]`。空队列名跳过；重复队列名导致发现失败；每个类**仅使用第一个** `#[Queued]`。

### 统计 {#stats}

`stats($queue)` 返回单队列的 `queue`、`prefix`、`ready`、`delayed`、`failed` 和 `counter`。`failed` 从 Redis 状态读取；本传输层**不**自行写入失败项。以 `.` 开头的队列名跳过计数哈希。

---

## 使用注意 {#keep-in-mind}

- `pull()` **从不**迁移延迟作业；必须有循环调用 `migrateDue()`。
- 只有在发现已经为信封队列解析出 Runner 后，`consume()` 才会成功。
- `queue:run` 使用约一秒的拉取窗口；`timeout` 为 `0` 时 `queue:work` 会阻塞。
- 点前缀队列名（例如 `.rpc.reply.{uuid}`）跳过统计计数。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#queue-faq)
- **设计拆分：** [设计说明](design.md#design-rationale)
- **并行执行：** [Executor 包](../executor/README.md)
- **Redis 传输：** [Redis 包](../redis/README.md)
