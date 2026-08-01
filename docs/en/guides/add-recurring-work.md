# Add recurring work {#add-recurring-work}

Use this guide when one existing service or cleanup/report path should now run on a time rule without turning durable queues or execution orchestration into the first solution.

## Choose the right recurring boundary {#recurring-boundary}

Start with the trigger model that matches the real job you need.

| Boundary | Best when | Keep in mind |
|---|---|---|
| [CLI](https://github.com/switon-php/cli/blob/main/docs/README.md) | a human, script, or operator still needs to trigger the work directly | keep this path when the manual command is not yet clear enough to automate |
| [Schedule](https://github.com/switon-php/schedule/blob/main/docs/README.md) | the same work should run at time-based intervals inside the app boundary | strongest default for recurring work; one task class maps to one schedule, and the scheduler still runs in a separate CLI process |
| [Executor](https://github.com/switon-php/executor/blob/main/docs/README.md) | the real problem is runner-style execution, grouping, or runtime-dependent submit behavior | stronger fit for execution orchestration than for the first time-based path |
| [Queue](https://github.com/switon-php/queue/blob/main/docs/README.md) | durable transport, delayed delivery, retries, or worker pull loops are the actual requirement | use this when durability and delivery semantics matter more than a simple time rule |

Common shape: **CLI** proves the manual path first, **Schedule** adds the time rule, **Executor** handles orchestration, and **Queue** handles durable delivery.

## Recommended build order {#build-order}

1. Start from one stable service or manual command.
2. Choose one obvious time rule first.
3. Add one thin task class around the same service.
4. Verify early with `schedule:list` and `schedule:task <name>`.
5. Add only minimal visibility for runs and failures.
6. Add `lockTtl` only when replica exclusivity is the real problem.
7. Stop before queue transport or worker-platform design.

## Minimal wiring {#minimal-wiring}

Use short code only to show the seam between the shared service, the manual command, and the scheduled task.

Application-level names like `TaskCleanupServiceInterface`, `TaskCleanupCommand`, and `PurgeCompletedTasksTask` below are illustrative. The Switon framework symbols and command surfaces are the stable part.

Keep the shared business logic in `app/Service/...`, the manual command in `app/Command/...`, and the scheduled task in `app/Task/...`.

### Shared cleanup service stays unchanged {#wiring-service}

Keep the cleanup rules behind one application boundary:

```php
namespace App\Service;

interface TaskCleanupServiceInterface
{
    public function purgeCompleted(): int;
}
```

### Manual command still stays useful {#wiring-command}

A recurring task is easier to trust when the same work still has a manual path:

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

### Add one scheduled task around the same service {#wiring-task}

Use [Schedule](https://github.com/switon-php/schedule/blob/main/docs/README.md) when the trigger becomes time-based:

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

`#[Scheduled('0 0 3 *')]` means `second=0`, `minute=0`, `hour=3`, `day-of-month=*`.
If you are used to Linux crontab, do not shift the fields left: the first field here is seconds.
By default, app tasks like this are discovered from `app/Task/*Task.php` and `app/Areas/*/Task/*Task.php`.
If your project keeps tasks somewhere else, configure `Switon\Schedule\TaskDiscoveryInterface` in root `switon.yml`.

### Run and verify it {#wiring-run}

From the app root, use the normal scheduler loop:

```bash
bash bin/console schedule:list
bash bin/console schedule:task <name>
bash bin/console schedule:run
```

Use `schedule:list` first so you can confirm the derived task name and expression.
Use `schedule:task <name>` as the fastest direct proof that the task body itself works.
Use `schedule:run` only to execute tasks that are actually due at the current time.
Adopt `bash bin/console schedule:work` only when you actually need a long-lived scheduler process.
If your project does not provide `bin/console`, follow the fallback entry shape in [CLI Quick Start](https://github.com/switon-php/cli/blob/main/docs/README.md#fallback-entry).

## What to avoid first {#pitfalls}

- **Scheduling work before the service or manual path is clear** — prove the business boundary first
- **Treating the expression like Linux five-field crontab** — Switon uses four fields only
- **Assuming the HTTP server runs scheduled work automatically** — the scheduler still needs its own CLI process
- **Expecting `schedule:run` to ignore expression timing** — use `schedule:task <name>` for the fastest direct proof of the task body
- **Using `lockTtl` everywhere by default** — distributed exclusivity is a later boundary, not the first answer
- **Jumping to `Executor` or `Queue` before the time-rule path is clear** — orchestration and durable transport solve different problems
- **Turning the first recurring task into a platform** — start with one useful task before retries, worker fleets, or large observability plans

## Go deeper {#go-deeper}

- **To connect this after your manual command path:** [Add CLI](add-cli.md#add-cli) · [06 — Logging and failures](../tutorial/06-log.md#06-log)
- **To learn the same move through the tutorial example:** [Timed cleanup](../tutorial/timed-cleanup.md#timed-cleanup)
- **To go deeper on time rules, task discovery, and scheduler commands:** [Schedule](https://github.com/switon-php/schedule/blob/main/docs/README.md)
- **To turn reminders or follow-ups into outbound mail with the same app service boundary:** [Send transactional email](send-transactional-email.md#send-transactional-email)
- **To keep the manual operator path clear:** [CLI](https://github.com/switon-php/cli/blob/main/docs/README.md#quick-start)
- **To add minimal visibility for recurring runs and failures:** [Log](https://github.com/switon-php/log/blob/main/docs/README.md#quick-start)
- **To add Redis-backed exclusivity or lock tuning:** [Redis](https://github.com/switon-php/redis/blob/main/docs/README.md) · [DI](https://github.com/switon-php/di/blob/main/docs/README.md#quick-start)
- **To hand the same work to durable workers next:** [Add durable background work](add-durable-background-work.md#add-durable-background-work) · [Queue](https://github.com/switon-php/queue/blob/main/docs/README.md)
- **To run the scheduler alongside HTTP and other workers in one go-live shape:** [Deploy an app to production](deploy-an-app-to-production.md#deploy-an-app-to-production)
- **To orchestrate runner execution instead of simple time rules:** [Executor](https://github.com/switon-php/executor/blob/main/docs/README.md)
