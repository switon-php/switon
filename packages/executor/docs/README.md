# Executor Package {#executor-package}

`switon/executor` gives Switon one execution API for container-registered runners, ordered batch results, first-success selection, and task-scoped concurrency control.

**Key features:**

- **One execution API:** inject `Switon\Executor\ExecutorInterface` and call `invoke()`, `submit()`, `invokeAll()`, or `invokeAny()`.
- **Runner-backed tasks:** every task id resolves to one `Switon\Core\RunnerInterface` service through the container.
- **Task identity split:** the segment before the first `:` resolves the runner service; the full task string stays the `submit()` gate key.
- **Ordered outcomes:** `invokeAll()` returns one `Switon\Executor\FutureInterface` per input slot in input order.
- **Background submit:** in coroutine-enabled runtime `submit()` schedules a Swoole coroutine; in serial runtime it runs in the current flow.
- **Run lifecycle events:** `Switon\Executor\Event\RunnerStarting` and `Switon\Executor\Event\RunnerFinished` wrap each `RunnerInterface::run()` call, even when it throws.

---

## Installation {#installation}

**Requirements:** PHP 8.3+; `ext-swoole` enables coroutine-enabled `submit()` and parallel batch paths

```bash
composer require switon/executor
```

---

## Quick Start {#quick-start}

- Implement one runner.
- Call it through `ExecutorInterface`.

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

- If you need first-success behavior, switch the same task pattern to `invokeAny()`.

---

## Core Concepts {#core-concepts}

### Task IDs {#task-ids}

A task id is the string you pass to the executor.

- `ReportRunner::class` resolves the runner service and uses the same string as the `submit()` gate key.
- `ReportRunner::class . ':tenant-a'` still resolves the same runner, but `submit()` uses the full string as its gate key.
- only the first `:` splits the runner id; later `:` characters stay part of the suffix

- The part before `:` must resolve to a `Switon\Core\RunnerInterface` service.

### Invocation Modes {#invocation-modes}

- `invoke($task, $payload)` runs one task in the current flow and rethrows runner failures after emitting lifecycle events.
- `submit($task, $payload, $concurrency = 1)` is fire-and-forget: in coroutine-enabled runtime it schedules a coroutine and acquires the per-task gate; in serial runtime it runs in the current flow.
- `invokeAll($tasks)` waits for every task and returns a `list<FutureInterface>` in input order, regardless of runtime.
- `invokeAny($tasks)` returns the first successful result, or `null` when none succeed.

`invokeAll()` and `invokeAny()` do not consult the `submit()` gate, and `invokeAny()` does not cancel coroutine tasks that are already running.

### Futures {#futures}

Use `Switon\Executor\FutureInterface` when you want to inspect each batch result.

- `isSuccessful()` tells you whether the runner finished without throwing; a successful task can still return `null`.
- `result()` returns the successful payload.
- `error()` returns the throwable for a failed task.

### Exceptions {#exceptions}

- `Switon\Executor\Exception\InvalidTaskException` — task id is empty, or the runner segment before `:` is empty.
- `Switon\Executor\Exception\TaskRunnerException` — the resolved service does not implement `Switon\Core\RunnerInterface`.
- `Switon\Executor\Exception\TaskSchedulingException` — Swoole refused to create a coroutine (raised from `submit()`, or recorded on the matching future for `invokeAll()`).

---

## Configuration {#configuration}

The stable controls are the active runtime and the `submit()` arguments:

- Coroutine-enabled runtime turns `submit()` into background work; otherwise it runs in the current flow.
- The `concurrency` argument on `submit()` caps in-flight coroutine submissions for that task string. Values less than `1` disable the gate.
- The full task string stays the gate key; the segment before `:` still resolves the runner service.

Durable transport, retries, and process supervision stay outside this package; reach for `switon/queue`, `switon/rpc`, or `switon/schedule` when those are the real requirement.

---

## Keep in Mind {#keep-in-mind}

- `submit()` returns `void`; use `invoke()` or `invokeAll()` when the caller needs a result.
- Use `:suffix` only when one runner service needs separate `submit()` gate buckets.
- `RunnerFinished` fires from `finally`, so it still runs (with `error` set) when the runner throws.
- `invokeAny()` returns the first successful result, not the first task in input order; failed attempts on coroutine runtime keep running until completion.

---

## Next {#next}

- **To answer common questions:** [FAQ](faq.md#executor-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
