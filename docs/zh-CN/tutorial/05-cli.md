---
sidebar_label: CLI 复用
description: 从控制台复用同一任务逻辑，证明应用代码不绑死在 HTTP 上。
---

# 05 — CLI 复用 {#05-cli}

从控制台运行同一任务逻辑，让读者看到应用代码不绑死在 HTTP 上。

## 目标 {#goal}

添加一条复用手动任务服务的命令，展示命令与控制器调用同一业务逻辑，并为定时清理支线做准备。

**主要包：** [CLI](../cli/README.md#quick-start)

## 代码 {#shape}

下文中的 `TaskCommand` 等应用命名仅为示例。命令命名约定、`ConsoleInterface` 与 `#[Autowired]` 是稳定部分。

```php
namespace App\Command;

use App\Service\TaskServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

final class TaskCommand
{
    #[Autowired] protected TaskServiceInterface $taskService;
    #[Autowired] protected ConsoleInterface $console;

    public function completeAction(int $id): void
    {
        $task = $this->taskService->complete($id);

        $this->console->success('Completed task {id}.', [
            'id' => $task['id'],
        ]);
    }
}
```

默认情况下，`TaskCommand::completeAction()` 成为 `task:complete`。规则：类名去掉 `Command`、方法名去掉 `Action`，再用冒号连接。`defaultAction()` 特例映射为裸命令名。到第 `04` 章，服务应已返回已存储的任务载荷。

要点是复用：控制器与命令都调用 `TaskServiceInterface`，服务无需了解 HTTP 或 CLI。

## 步骤 {#steps}

1. 添加最小的有用手动动作（例如完成任务）。一条命令足以让复用模式感觉真实。
2. 让命令接收参数并打印成功/失败；服务仍决定业务行为。首轮成功走控制台消息，失败走异常。
3. 深入调试前先检查 CLI：`list`、执行命令、`--help`。

```bash
bash bin/console list
bash bin/console task:complete 42
bash bin/console task:complete --help
```

若命令未出现：确认类在 `App\Command` 或已扫描路径下，且遵循 `*Command` / `*Action()` 命名。

4. 本章保持手动；需要时间触发或后台执行时再由支线接续。

## 验证 {#verify}

确认 `task:complete` 能驱动与 HTTP `complete` 相同的 service 路径，并在控制台给出可读反馈。

## 下一步 {#next}

主路径最后一章是 [06 — 日志与失败](06-log.md#06-log)：在共享服务周围建立最小的日志习惯。
