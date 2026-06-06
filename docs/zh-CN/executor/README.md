---
sidebar_label: Executor 包
description: switon/executor：invoke/submit、invokeAll/invokeAny、任务 ID 与 Future。
---

# Executor 包 {#executor-package}

`switon/executor` 为 Switon 提供统一的执行 API：容器注册的 runner、有序批量结果、首个成功选取，以及任务级的并发控制。

**关键特性：**

- **单一执行 API：** 注入 `Switon\Executor\ExecutorInterface`，调用 `invoke()`、`submit()`、`invokeAll()` 或 `invokeAny()`。
- **基于 Runner 的任务：** 每个任务 id 通过容器解析为一个 `Switon\Core\RunnerInterface` 服务。
- **任务身份拆分：** 第一个 `:` 之前的段解析 runner 服务；**完整**任务字符串仍是 `submit()` 的门控键。
- **有序结果：** `invokeAll()` 按输入槽顺序返回 `Switon\Executor\FutureInterface` 列表。
- **后台 submit：** 启用协程的运行时里 `submit()` 调度 Swoole 协程；串行运行时仍在当前流程执行。
- **运行生命周期事件：** `Switon\Executor\Event\RunnerStarting` 与 `Switon\Executor\Event\RunnerFinished` 包裹每次 `RunnerInterface::run()`，含抛错情况。

## 安装 {#installation}

**环境要求：** PHP 8.3+；`ext-swoole` 启用协程型 `submit()` 与并行批量路径。

```bash
composer require switon/executor
```

## 快速开始 {#quick-start}

- 实现一个 runner。
- 通过 `ExecutorInterface` 调用。

```php
namespace App\Runner;

use Switon\Core\RunnerInterface;

final class ReportRunner implements RunnerInterface
{
    public function run(mixed $payload): array
    {
        return ['date' => $payload['date'] ?? null];
    }
}
```

```php
namespace App\Service;

use App\Runner\ReportRunner;
use Switon\Core\Attribute\Autowired;
use Switon\Executor\ExecutorInterface;

final class ReportService
{
    #[Autowired] protected ExecutorInterface $executor;

    public function export(): mixed
    {
        $future = $this->executor->invokeAll([
            [ReportRunner::class, ['date' => '2026-01-01']],
        ])[0];

        return $future->isSuccessful() ? $future->result() : null;
    }

    public function submitLater(): void
    {
        $this->executor->submit(ReportRunner::class . ':tenant-a', ['date' => '2026-01-01'], 1);
    }
}
```

需要「任一成功即可」时，同一任务模式可改用 `invokeAny()`。

## 核心概念 {#core-concepts}

### 任务 ID {#task-ids}

任务 id 即传给执行器的字符串。

- `ReportRunner::class` 解析 runner 服务，且 `submit()` 门控键就是该字符串。
- `ReportRunner::class . ':tenant-a'` 仍解析同一 runner，但 `submit()` 用**完整**字符串作门控键。
- **只有第一个** `:` 拆分 runner id，后面的 `:` 留在后缀里。

`:` 前一段必须解析为 `Switon\Core\RunnerInterface` 服务。

### 调用模式 {#invocation-modes}

- `invoke($task, $payload)` 在当前流程跑单个任务，发出生命周期事件后 runner 失败会重抛。
- `submit($task, $payload, $concurrency = 1)` 即发即忘：协程运行时调度协程并占用该任务串的门控；串行运行时仍在当前流程执行。
- `invokeAll($tasks)` 等待全部任务，按输入顺序返回 `list<FutureInterface>`，与运行时无关。
- `invokeAny($tasks)` 返回首个成功结果；全部失败则 `null`。

`invokeAll()` 与 `invokeAny()` **不**查 `submit()` 门控；`invokeAny()` **不会**取消已在跑的协程任务。

### Future {#futures}

用 `Switon\Executor\FutureInterface` 检查批量中的每一项。

- `isSuccessful()` 表示 runner 是否未抛错；成功任务的结果仍可能为 `null`。
- `result()` 返回成功载荷。
- `error()` 返回失败任务上的 throwable。

### 异常 {#exceptions}

- `Switon\Executor\Exception\InvalidTaskException` —— 任务 id 为空，或 `:` 前前缀为空。
- `Switon\Executor\Exception\TaskRunnerException` —— 解析到的服务未实现 `Switon\Core\RunnerInterface`。
- `Switon\Executor\Exception\TaskSchedulingException` —— Swoole 拒绝创建协程（`submit()` 抛出，或在 `invokeAll()` 的对应 future 上记录）。

## 配置 {#configuration}

稳定控制面主要是当前运行时与 `submit()` 参数：

- 协程运行时把 `submit()` 变成后台工作；否则仍在当前流程执行。
- `submit()` 的 `concurrency` 限制该任务字符串下在途协程提交数；**小于 `1`** 关闭门控。
- 完整任务串是门控键；`:` 前一段仍解析 runner。

持久传输、重试与进程监管不在本包内；真正需要时请用 `switon/queue`、`switon/rpc` 或 `switon/schedule`。

## 使用注意 {#keep-in-mind}

- `submit()` 返回 `void`；调用方要结果请用 `invoke()` 或 `invokeAll()`。
- 仅当同一 runner 服务需要多个 `submit()` 桶时才使用 `:suffix`。
- `RunnerFinished` 来自 `finally`，runner 抛错时仍会触发（并带上 `error`）。
- `invokeAny()` 返回首个成功结果，不一定是输入顺序中的第一项；协程运行时上失败的尝试会继续跑到结束。

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#executor-faq)
- **取舍与设计：** [设计说明](design.md#design-rationale)
