---
sidebar_label: Logging
description: Add the minimum logging path so failures and key task operations become visible.
---

# 06 — Logging and failures {#06-log}

## Goal {#goal}

Add minimal service-level logs for success and failure paths.

**Primary packages:** [Log](https://github.com/switon-php/log/blob/main/docs/README.md) · [HTTP](https://github.com/switon-php/http/blob/main/docs/README.md)

## Code {#shape}

`TaskService` is an illustrative name.

```php
namespace App\Service;

use App\Entity\Task;
use App\Repository\TaskRepository;
use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;

final class TaskService implements TaskServiceInterface
{
    #[Autowired] protected TaskRepository $taskRepository;
    #[Autowired] protected LoggerInterface $logger;

    public function complete(int $id): array
    {
        $task = $this->taskRepository->find($id);
        if (!$task instanceof Task) {
            $this->logger->warning('Task {id} was not found.', ['id' => $id]);

            return [
                'id' => $id,
                'completed' => false,
                'found' => false,
            ];
        }

        $task->completed = true;
        $task = $this->taskRepository->update($task);

        $payload = [
            'id' => $task->id,
            'title' => $task->title,
            'note' => $task->note,
            'completed' => (bool) $task->completed,
            'found' => true,
        ];

        $this->logger->info('Completed task {id}', [
            'id' => $payload['id'],
        ]);

        return $payload;
    }
}
```

This chapter adds logs to the repository-backed `complete()` path from chapter `04`.
Keep the rules simple:

- use placeholders such as `{id}` for stable message templates
- keep extra context structured
- log enough to explain the event or failure
- keep the service result and the log message aligned

Log both the success path and the "task not found" path.

## Steps {#steps}

1. Add one success log at the service boundary.
2. Add one failure log where debugging would otherwise be opaque.
3. Keep the command or controller responsible for deciding how to present the failure.

## Verify {#verify}

Trigger one successful completion and one failing completion.
Confirm that both the success log and failure log reach your current log output, such as stdout or your configured log file.

## Next {#next}

The main path stops here. Pick an optional side path next—[Timed cleanup](timed-cleanup.md#timed-cleanup) for recurring work, or [Faster list](faster-list.md#faster-list) when reads need a cheaper path.
