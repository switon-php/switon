---
sidebar_label: 定时清理
description: 在主教程路径就绪后，按计划在周期上执行重复性清理工作。
---

# 旁路 — 定时清理 {#timed-cleanup}

## 目标 {#goal}

用周期任务做重复清理，使基于时间的工作落在同一 service 边界内。

## 代码 {#shape}

下文中的 `CleanupCompletedTasksTask` 等应用命名仅为示例。下面片段展示新增的计划任务部分，请合并进现有 service 接口与实现，而非整类替换。

Switon 在此上下文中使用**四个**调度字段：`#[Scheduled('0 */15 * *')]` 表示秒为 `0`、每 `15` 分钟、每小时、每个 day-of-month。若你熟悉 Linux crontab，不要把字段左移：此处**第一项是秒**。

```php
namespace App\Task;

use App\Service\TaskServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

#[Scheduled('0 */15 * *')]
final class CleanupCompletedTasksTask implements RunnerInterface
{
    #[Autowired] protected TaskServiceInterface $taskService;

    public function run(mixed $payload): mixed
    {
        return $this->taskService->cleanupCompleted();
    }
}
```

`Schedule` 负责**何时**运行；服务仍拥有清理规则。先在服务层增加一个方法：

```php
namespace App\Service;

interface TaskServiceInterface
{
    public function cleanupCompleted(): int;
}

final class TaskService implements TaskServiceInterface
{
    public function cleanupCompleted(): int
    {
        // Keep the first pass simple: remove or archive tasks that are
        // already marked completed.
        return 0;
    }
}
```

本旁路仍停留在 [04 — 存储数据](04-data.md#04-data) 的模型内：任务有 `completed` 标志，尚无完成时间戳。若需要「完成超过 N 天」等规则，先扩展存储再演进清理逻辑。若日后要完整调度地图，见 [Schedule](../schedule/README.md) 包指南。

## 步骤 {#steps}

1. 在服务层添加一个清理动作。
2. 在常规任务发现路径下添加计划任务类。
3. 用 `schedule:list` 验证注册。
4. 用 `schedule:task <name>` 手动跑一次，证明任务体可用。
5. 仅在当前时间匹配表达式时，或暂时改表达式做快速验证后，再使用 `schedule:run`。
6.（可选）加一条小日志或可见信号，便于确认周期运行。

## 验证 {#verify}

在应用根目录使用公开命令：

```bash
bash bin/console schedule:list
bash bin/console schedule:task cleanup-completed-tasks
bash bin/console schedule:run
```

对本教程类名，派生任务 id 为 `cleanup-completed-tasks`。先运行 `schedule:list` 确认派生名再调用 `schedule:task <name>`。表达式细节见 [Schedule 表达式](../schedule/README.md)。长期运行调度进程见包文档中的 `schedule:work`。

## 下一步 {#next}

周期性执行清楚后，回到 [教程索引](README.md#main-path)；若读性能是下一问题可打开 [更快的列表](faster-list.md#faster-list)。
