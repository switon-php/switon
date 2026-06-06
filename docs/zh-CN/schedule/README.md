---
sidebar_label: Schedule 包
description: switon/schedule：#[Scheduled]、四段表达式、schedule:run/work 与 lockTtl。
---

# Schedule 包 {#schedule-package}

`switon/schedule` 针对**同一应用时钟**与**同一任务注册表**运行周期性工作（清理、同步、报表等）。它**刻意不是**完整 crontab：没有星期与月份字段，也没有 per-task 时区，使时间规则保持简短，运维只需核对**一个**时区。

**关键特性：**

- **周期任务：** 实现 `Switon\Core\RunnerInterface` 并带有 `#[Scheduled]` 的类。
- **应用时区：** 表达式在 `AppInterface::timezone()` 下匹配。
- **可选锁：** `lockTtl` 在某一 tick 必须全局只跑一次时，通过 Redis NX+EX 互斥。
- **任务发现：** 应用扫描路径加上包内的 `extra.switon.tasks`。
- **CLI：** `schedule:list`、`schedule:run`、`schedule:task` 与 `schedule:work`。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+，以及负责任务发现、执行、锁定与事件的框架组件（`switon/core`、`switon/executor`、`switon/event`、`switon/redis`、`switon/cli`、`switon/composer-extra`）。

```bash
composer require switon/schedule
```

---

## 快速开始 {#quick-start}

```php
namespace App\Task;

use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

#[Scheduled('0 * * *')]
final class CleanupTask implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
```

从 CLI 运行调度器：

```bash
bash bin/console schedule:list
bash bin/console schedule:run
```

---

## 核心概念 {#core-concepts}

### 任务 {#tasks}

周期任务是实现 `Switon\Core\RunnerInterface` 并带有类级 `#[Scheduled]` 的类。调度器以 `null` 调用 `run(mixed $payload)`。

### 表达式 {#expressions}

表达式使用四个字段：`秒 分 时 月内日`。每段仅接受 `*`、具体整数或 `*/n`（`*/0` 无效）。不支持列表、范围、星期与月份字段。匹配使用应用时区。

### 锁 {#locks}

`lockTtl` 按任务配置，绑定在 `1…86400` 秒（或 `0`）。

- `0` — 无分布式锁；每个副本都可能运行同一 tick。
- `1…86400` — Redis NX+EX，键包含 app id 与任务名。

若无法取得锁，该 tick 跳过且不触发任务事件。

### 发现 {#discovery}

应用任务来自 `@app/Task` 与 `@app/Areas/*/Task`；包任务来自 `extra.switon.tasks`。任务名由类名推导（去掉根命名空间、去掉重复前导 `Task`、去掉第二段的 `Areas`、去掉尾部 `Task` 后缀，点分段转 `kebab-case`）。发现在 `ServiceProvider::boot()` 期间运行，**boot 之后**加载的类不会自动注册。

### 运行 {#running}

- `schedule:run` — 执行一轮到期任务后退出；若有任务失败则非零退出；适合 cron。
- `schedule:work` — 在长驻进程中按 `tick` 毫秒持续 tick；需要协程运行时支持。
- `schedule:task <name>` — 立即运行一个任务，**跳过**表达式匹配。

---

## 配置 {#configuration}

多数应用只配置发现路径、调度器 `tick` 以及用于锁定的 Redis 客户端。

```yaml
Switon\Schedule\TaskDiscoveryInterface:
  tasks:
    '@app/Task/*Task.php': 'App\\Task\\*Task'
    '@app/Areas/*/Task/*Task.php': 'App\\Areas\\*\\Task\\*Task'

Switon\Schedule\Command\ScheduleCommand:
  tick: 1000
```

### 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Schedule\TaskDiscoveryInterface'
bash bin/console config:options 'Switon\Schedule\Command\ScheduleCommand'
bash bin/console config:options 'Switon\Schedule\LockInterface'
```

---

## 使用注意 {#keep-in-mind}

- 表达式为**四段**，不是 crontab；无星期、月份或 per-task 时区。
- `lockTtl: 0` **不**协调多副本；多调度副本跑同一任务时请使用正数。
- HTTP 服务器**不会**自动跑调度；请在单独进程运行 `schedule:run`（cron）或 `schedule:work`（长驻）。
- 类上**仅第一个** `#[Scheduled]` 生效。

---

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#schedule-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **队列后台作业：** [Queue 包](../queue/README.md)
- **生产部署：** [将应用部署到生产环境](../guides/deploy-an-app-to-production.md#deploy-an-app-to-production)
