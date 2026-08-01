---
sidebar_label: CLI Reuse
description: Reuse the same task logic from the console so application code is not tied to HTTP only.
---

# 05 — CLI reuse {#05-cli}

## Goal {#goal}

Run the same task logic from CLI to prove entrypoint reuse.

**Primary package:** [CLI](../../packages/cli/docs/README.md#quick-start)

## Code {#shape}

`TaskCommand` is an illustrative name.

```php
namespace App\Command;

use App\Service\TaskServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

final class TaskCommand
{
    #[Autowired] protected TaskServiceInterface $taskService;
    #[Autowired] protected ConsoleInterface $console;

    public function completeAction(int $id): int
    {
        $task = $this->taskService->complete($id);
        if (($task['found'] ?? true) === false) {
            return $this->console->error('Task {id} was not found.', ['id' => $id], ConsoleInterface::FAILURE);
        }

        $this->console->success('Completed task {id}.', [
            'id' => $task['id'],
        ]);

        return ConsoleInterface::SUCCESS;
    }
}
```

By default, `TaskCommand::completeAction()` becomes the `task:complete` command. The rule is literal:

- remove the `Command` suffix from the class name, then convert `Task` to `task`
- remove the `Action` suffix from the method name, then convert `complete` to `complete`
- join them as `task:complete`

`defaultAction()` maps to the bare command name, such as `task`.

By chapter `04`, the service returns stored task payloads, so the command can print the real `id`.
If `complete($id)` cannot find a task, return a normal failure result and let the command print the error.

## Steps {#steps}

1. Add one small manual command, such as `task:complete`.
2. Keep CLI input and output in the command class.
3. Let the service keep business decisions and failure behavior.

## Verify {#verify}

Use the public CLI loop:

```bash
bash bin/console list
bash bin/console task:complete 1
bash bin/console task:complete 42
bash bin/console task:complete --help
```

`list` confirms discovery.
`task:complete 1` confirms the success path.
`task:complete 42` confirms the not-found path.
`--help` confirms command contract visibility.

If the command does not appear, check the two basics first:

- the class lives under `App\Command` or another scanned command path
- the class and method still follow the `*Command` and `*Action()` naming rule

## Next {#next}

Finish the main path with [06 — Logging and failures](06-log.md#06-log): one small logging habit around the shared service.
