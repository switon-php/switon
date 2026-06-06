---
description: 让已有 service 或清理/报表路径按时间规则运行，而不把可持久队列或执行编排当作首选方案。
---

# 添加周期性任务 {#add-recurring-work}

当已有 service 或清理/报表路径应按时间规则运行，且你不希望把可持久队列或执行编排当作首选方案时，使用本指南。

## 选对周期边界 {#recurring-boundary}

从与实际工作匹配的触发模型起步。

| 边界 | 最适合 | 注意 |
|---|---|---|
| [CLI](../cli/README.md) | 人、脚本或运维仍需直接触发工作 | 当手动命令尚不够清晰、不宜自动化时保留此路径 |
| [Schedule](../schedule/README.md) | 同一工作应在应用边界内按时间间隔运行 | 周期工作的最强默认；一个任务类对应一个调度；调度器仍在独立 CLI 进程中运行 |
| [Executor](../executor/README.md) | 真正问题是 runner 式执行、分组或依赖运行时的提交行为 | 比第一条基于时间的路径更适合执行编排 |
| [Queue](../queue/README.md) | 可持久传输、延迟投递、重试或 worker 拉取环才是实际需求 | 当耐久性与投递语义比简单时间规则更重要时使用 |

一种常见的应用形态是：

- **CLI** 先证明手动路径
- **Schedule** 在同一 service 边界上按时间规则触发
- **Executor** 当执行语义变难
- **Queue** 当工作必须跨越进程边界并承受投递失败

## 推荐构建顺序 {#build-order}

1. 从一条稳定的 service 或手动命令起步（业务逻辑勿散在控制器或裸 shell）。
2. 先选一个能用一句话说清的时间规则（Switon 为**四字段**：`second minute hour day-of-month`，不是五字段 cron）。
3. 在同一 service 外增加一个薄的 `RunnerInterface` 任务类，把调度留在任务层。
4. 尽早验证：`schedule:list` 与 `schedule:task <name>`。
5. 仅为运行与失败增加**最小**可见性。
6. 仅当多副本互斥是真正问题时再为任务设置正的 `lockTtl`（见 [Schedule](../schedule/README.md#locks)）。
7. 在队列传输或 worker 平台设计之前止步。

## 最小接线 {#minimal-wiring}

仅用短代码展示共享 service、手动命令与定时任务之间的接缝。

下文中的 `TaskCleanupServiceInterface`、`TaskCleanupCommand`、`PurgeCompletedTasksTask` 等应用级名称仅为示意。Switon 框架符号与命令表面才是稳定部分。

在代码之前，保持放置规则简单：

- 共享业务逻辑放在 `app/Service/...`
- 手动命令放在 `app/Command/...`
- 定时任务放在 `app/Task/...`
- 仅当不想用默认 `app/Task/*Task.php` 路径时，在根目录 `switon.yml` 中配置自定义任务发现

### 共享清理 service 保持不变 {#wiring-service}

把清理规则放在一条应用边界之后：

```php
namespace App\Service;

interface TaskCleanupServiceInterface
{
    public function purgeCompleted(): int;
}
```

### 手动命令仍然有用 {#wiring-command}

当同一工作仍有手动路径时，周期任务更容易信任：

```php
namespace App\Command;

use App\Service\TaskCleanupServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

class TaskCleanupCommand
{
    #[Autowired] protected TaskCleanupServiceInterface $cleanup;
    #[Autowired] protected ConsoleInterface $console;

    public function runAction(): void
    {
        $removed = $this->cleanup->purgeCompleted();
        $this->console->success('Purged {count} completed tasks.', ['count' => $removed]);
    }
}
```

### 在同一 service 外增加一条定时任务 {#wiring-task}

当触发变为基于时间时，使用 [Schedule](../schedule/README.md)：

```php
namespace App\Task;

use App\Service\TaskCleanupServiceInterface;
use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

#[Scheduled('0 0 3 *')]
final class PurgeCompletedTasksTask implements RunnerInterface
{
    #[Autowired] protected TaskCleanupServiceInterface $cleanup;
    #[Autowired] protected LoggerInterface $logger;

    public function run(mixed $payload): mixed
    {
        $removed = $this->cleanup->purgeCompleted();
        $this->logger->info('Purged completed tasks', ['count' => $removed]);

        return $removed;
    }
}
```

`#[Scheduled('0 0 3 *')]` 表示 `second=0`、`minute=0`、`hour=3`、`day-of-month=*`。
默认情况下，此类应用任务从 `app/Task/*Task.php` 与 `app/Areas/*/Task/*Task.php` 发现。
若项目把任务放在别处，在根目录 `switon.yml` 中配置 `Switon\Schedule\TaskDiscoveryInterface`。

职责保持清晰：

- service 决定**应用做什么**
- 命令决定**运维如何手动运行**
- 任务类决定**调度器何时应触发**

### 运行并验证 {#wiring-run}

在应用根目录使用常规调度环：

```bash
bash bin/console schedule:list
bash bin/console schedule:task <name>
bash bin/console schedule:run
```

先用 `schedule:list` 确认派生的任务名与表达式。
用 `schedule:list` 显示的值作为 `schedule:task <name>` 的参数，是证明任务体本身最直接的方式。
`schedule:run` 仅用于执行当前时刻确实到期的任务。
仅当你确实需要常驻调度进程时，再采用 `bash bin/console schedule:work`。
若项目未提供 `bin/console`，按 [CLI 快速开始](../cli/README.md#fallback-entry) 中的备用入口形态操作。

第一条成功线很小：
- 一条共享 service 仍拥有业务工作
- 一条手动路径仍然存在
- 一条周期任务现已拥有时间触发

## 初期应避免什么 {#pitfalls}

有意让第一条周期路径保持狭窄。

过早避免：

- **在 service 或手动路径尚不清晰时就调度工作** — 先证明业务边界
- **把表达式当作 Linux 五字段 crontab** — Switon 仅使用四个字段
- **假定 HTTP 服务器会自动运行定时工作** — 调度器仍需要自己的 CLI 进程
- **期望 `schedule:run` 忽略表达式时间** — 用 `schedule:task <name>` 最快直接证明任务体
- **默认到处使用 `lockTtl`** — 分布式互斥是后续边界，不是首个答案
- **在时间规则路径尚不清晰时跳到 `Executor` 或 `Queue`** — 编排与可持久传输解决的是不同问题
- **把第一条周期任务变成平台** — 在重试、worker 集群或大型可观测性计划之前，先从一个有用任务开始

完成第一条周期路径后，初学者应能感到：

> 我知道一条已有 service 如何变成周期工作，而不把应用变成完整作业系统。

## 继续深入 {#go-deeper}

- **在手动命令路径之后衔接：** [添加 CLI](add-cli.md#add-cli) · [06 — 日志与失败](../tutorial/06-log.md#06-log)
- **通过教程示例学习同样动作：** [定时清理](../tutorial/timed-cleanup.md#timed-cleanup)
- **深入时间规则、任务发现与调度命令：** [Schedule](../schedule/README.md)
- **用同一应用 service 边界把提醒或跟进变成外发邮件：** [发送事务性邮件](send-transactional-email.md#send-transactional-email)
- **保持手动运维路径清晰：** [CLI](../cli/README.md#quick-start)
- **为周期运行与失败增加最小可见性：** [Log](../log/README.md#quick-start)
- **添加 Redis 支撑的互斥或锁调优：** [Redis](../redis/README.md) · [DI](../di/README.md#quick-start)
- **下一步把同一工作交给可持久 worker：** [添加持久化后台工作](add-durable-background-work.md#add-durable-background-work) · [Queue](../queue/README.md)
- **在 HTTP 与其他 worker 的同一上线形态中运行调度器：** [将应用部署到生产环境](deploy-an-app-to-production.md#deploy-an-app-to-production)
- **编排 runner 执行而非简单时间规则：** [Executor](../executor/README.md)
