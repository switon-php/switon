---
sidebar_label: Timed Cleanup
description: Run recurring cleanup work on a schedule after the main tutorial path is in place.
---

# Side path — Timed cleanup {#timed-cleanup}

## Goal {#goal}

Run recurring cleanup so time-based work fits into the same service boundary.

## Code {#shape}

`CleanupCompletedTasksTask` is an illustrative name.
The snippets below show the added scheduled-task parts. Merge them into your existing service interface and service instead of replacing the whole class.

Switon schedule expressions use **four** fields in this context.
`#[Scheduled('0 */15 * *')]` means second `0`, every `15` minutes, every hour, every day-of-month.
If you are used to Linux crontab, do not shift the fields left: the first field here is seconds.

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

`Schedule` owns **when** the task runs. The service still owns cleanup rules.

Add one service method first:

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

This side path stays within the model from [04 — Stored data](04-data.md#04-data): tasks have `completed`, not completion timestamps.

## Steps {#steps}

1. Add one cleanup action to the service layer.
2. Add one scheduled task class under the normal task discovery path.
3. Verify registration with `schedule:list`.
4. Run the task once with `schedule:task <name>` to prove the task body itself works.
5. Use `schedule:run` only when the current time matches the expression, or after a temporary expression change.

## Verify {#verify}

Use the public commands from the app root:

```bash
bash bin/console schedule:list
bash bin/console schedule:task cleanup-completed-tasks
bash bin/console schedule:run
```

For this class name, the derived task id is `cleanup-completed-tasks`.
Run `schedule:list` first and confirm the derived id before calling `schedule:task <name>`.

## Next {#next}

When recurring execution is clear, return to the [tutorial index](README.md#main-path), or open [Faster list](faster-list.md#faster-list) if read performance is the next question.
