# Add CLI {#add-cli}

Use this guide when you want to expose existing application logic from the console so the same business path can run without going through HTTP.

## Boundary {#execution-boundary}

Start with the trigger model that matches the job you actually need.

| Boundary | Best when | Keep in mind |
|---|---|---|
| [CLI](https://github.com/switon-php/cli/blob/main/docs/README.md) | a human, script, or operator should run one command directly | best default for manual entrypoints; keep business rules in services, not in command methods |
| [Schedule](https://github.com/switon-php/schedule/blob/main/docs/README.md) | the same work should run at time-based intervals | usually add this only after the command path is already clear and runnable |
| [Executor](https://github.com/switon-php/executor/blob/main/docs/README.md) | the main problem is runner-style execution, grouping, or runtime-dependent submit behavior | stronger fit for execution orchestration than for the first manual command path |

Common shape: **CLI** for manual entrypoints, **Schedule** for time-based triggers, **Executor** for runner orchestration.

## Build Order {#build-order}

1. Start only after the business action already lives in a service.
2. Add one command class under a scanned path such as `App\Command`.
3. Keep CLI input/output in the command and business rules in the service.
4. Verify early with `list`, one real run, and `--help`.
5. Stop before you branch into `Schedule` or `Executor`.

## Minimal wiring {#minimal-wiring}

Use short code only to show the seam between the shared service and the CLI entrypoint.

Application-level names like `TaskServiceInterface`, `TaskController`, and `TaskCommand` below are illustrative. The Switon framework symbols and command conventions are the stable part.
For a first pass, put the command in `app/Command/TaskCommand.php`.

### Shared service stays unchanged {#wiring-service}

Keep one shared service boundary:

```php
namespace App\Service;

interface TaskServiceInterface
{
    public function complete(int $id): array;
}
```

Your HTTP controller can keep calling that service exactly as before:

```php
namespace App\Controller;

use App\Service\TaskServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\PutMapping;

#[RequestMapping('')]
class TaskController
{
    #[Autowired] protected TaskServiceInterface $taskService;

    #[PutMapping('/tasks/{id}/complete')]
    public function completeAction(int $id): array
    {
        return ['task' => $this->taskService->complete($id)];
    }
}
```

If the app adds a global prefix such as `/api`, the public route becomes `/api/tasks/{id}/complete` even though the controller mapping stays `'/tasks/{id}/complete'`.

### Add one command class around the same service {#wiring-command}

Add one normal command class under a scanned command path:

```php
namespace App\Command;

use App\Service\TaskServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

class TaskCommand
{
    #[Autowired] protected TaskServiceInterface $taskService;
    #[Autowired] protected ConsoleInterface $console;

    public function completeAction(int $id): int
    {
        $task = $this->taskService->complete($id);
        if (($task['found'] ?? true) === false) {
            return $this->console->error('Task {id} was not found.', ['id' => $id], ConsoleInterface::FAILURE);
        }

        $this->console->success('Completed task {id}.', ['id' => $task['id'] ?? $id]);

        return ConsoleInterface::SUCCESS;
    }
}
```

By default, `TaskCommand::completeAction()` becomes the `task:complete` command name.
The rule is mechanical:

- remove the `Command` suffix from the class name, then convert it to kebab-case: `TaskCommand` -> `task`
- remove the `Action` suffix from the method name, then convert it to kebab-case: `completeAction` -> `complete`
- join them as `command:action`

### Run and verify it {#wiring-run}

From the app root, use:

```bash
bash bin/console list
bash bin/console task:complete 1
bash bin/console task:complete 42
bash bin/console task:complete --help
```

You want to see `task:complete` in `list` before debugging the command body itself.
If the command does not appear, check the class location first (`App\Command` or another scanned command path) and then the naming rule (`*Command` with public `*Action()` methods).

## Pitfalls {#pitfalls}

- **Moving business rules into command methods** — commands should coordinate console input and output, not own the application rules
- **Reaching into the container manually when normal DI already fits** — stay on typed dependencies and `#[Autowired]`
- **Designing a whole operator surface before one command works** — prove one useful entrypoint first
- **Putting the first command outside the normal discovery path** — keep the beginner path under `App\Command` unless your app already teaches a different scanned location
- **Jumping to `Schedule` or `Executor` before the manual command is clear** — trigger model changes come after the first runnable path
- **Treating the first command like a tooling platform** — `#[Tool]`, hidden commands, completion, and richer inventories are later concerns
- **Turning success/failure messages into a full logging architecture** — add only enough feedback for the first operator loop

## Next {#go-deeper}

- **To connect this after your first API and stored-data paths:** [Build an API](build-an-api.md#build-an-api) · [Persist data](persist-data.md#persist-data)
- **To learn the same move chapter by chapter:** [05 — CLI reuse](../tutorial/05-cli.md#05-cli)
- **To go deeper on plain command classes and discovery:** [CLI](https://github.com/switon-php/cli/blob/main/docs/README.md#quick-start) · [Discovery](https://github.com/switon-php/cli/blob/main/docs/README.md#discovery) · [Visibility](https://github.com/switon-php/cli/blob/main/docs/README.md#visibility)
- **To keep service wiring clean and interface-first:** [DI](https://github.com/switon-php/di/blob/main/docs/README.md#quick-start)
- **To add minimal visibility for command failures next:** [06 — Logging and failures](../tutorial/06-log.md#06-log) · [Log](https://github.com/switon-php/log/blob/main/docs/README.md#quick-start)
- **To move from manual commands into recurring work:** [Add recurring work](add-recurring-work.md#add-recurring-work) · [Timed cleanup](../tutorial/timed-cleanup.md#timed-cleanup) · [Schedule](https://github.com/switon-php/schedule/blob/main/docs/README.md)
- **To orchestrate execution instead of doing work inline:** [Executor](https://github.com/switon-php/executor/blob/main/docs/README.md)
