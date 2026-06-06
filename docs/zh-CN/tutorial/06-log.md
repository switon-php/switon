---
sidebar_label: 日志
description: 添加最小日志路径，使失败与关键任务操作可见。
---

# 06 — 日志与失败 {#06-log}

添加最小日志路径，使开发与运行中的错误与重要任务操作可见。

## 目标 {#goal}

在服务层为成功与失败路径添加最小日志。

**主要包：** [Log](../log/README.md) · [HTTP](../http/README.md)

## 代码 {#shape}

下文中的 `TaskService` 等应用命名仅为示例。`Psr\Log\LoggerInterface` 与常规 PSR-3 占位符风格是稳定部分。

```php
namespace App\Service;

use App\Repository\TaskRepository;
use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;

final class TaskService implements TaskServiceInterface
{
    #[Autowired] protected TaskRepository $taskRepository;
    #[Autowired] protected LoggerInterface $logger;

    public function complete(int $id): array
    {
        try {
            $task = $this->taskRepository->get($id);
            $task->completed = true;
            $task = $this->taskRepository->update($task);

            $payload = [
                'id' => $task->id,
                'title' => $task->title,
                'note' => $task->note,
                'completed' => $task->completed,
            ];

            $this->logger->info('Completed task {id}', [
                'id' => $payload['id'],
            ]);

            return $payload;
        } catch (\Throwable $e) {
            $this->logger->error('Task completion failed', ['exception' => $e, 'id' => $id]);
            throw $e;
        }
    }
}
```

本章在仓储支撑的 `complete()` 上增加日志：用 `{id}` 等占位符稳住消息形态，结构化附加上下文，失败时把 throwable 放在 `exception` 键下。教程保持 `get()` 表示「必须存在」：`TaskRepository::get()` 在未知 `id` 上会失败，服务记录并重新抛出，HTTP 与 CLI 仍走真实失败路径。

## 步骤 {#steps}

1. 在业务边界添加一条成功日志（如任务完成）；服务层记录比仅在控制器记录更可复用。
2. 在否则难以诊断处添加失败日志：捕获异常并附结构化上下文；需要调用方感知失败时继续抛出。
3. 确认当前 setup 下日志去向（本机文件或 stdout）。不确定时先读 [Log](../log/README.md) 包文档。

若需要，也可同时记录「未找到」等非异常分支；主路径以保持范围务实为先。

## 验证 {#verify}

分别触发一次成功完成与一次失败完成，确认成功与失败日志都到达当前输出（stdout 或配置的文件）。

## 下一步 {#next}

主路径到此结束。可选旁路：[定时清理](timed-cleanup.md#timed-cleanup)，或 [更快的列表](faster-list.md#faster-list)。
