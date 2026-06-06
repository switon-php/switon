# Schedule Package {#schedule-package}

`switon/schedule` runs recurring work — cleanup, sync, reports — against one shared app clock and one task registry. It is intentionally not full crontab: there are no weekday or month fields and no per-task timezone, so timing rules stay short and operators only check one timezone.

**Key features:**

- **Recurring tasks:** classes that implement `Switon\Core\RunnerInterface` and carry `#[Scheduled]`.
- **App timezone:** expressions match against `AppInterface::timezone()`.
- **Optional locking:** `lockTtl` adds Redis NX+EX exclusivity when one tick must run once across replicas.
- **Task discovery:** app scan paths plus `extra.switon.tasks` from packages.
- **CLI operation:** `schedule:list`, `schedule:run`, `schedule:task`, and `schedule:work`.

---

## Installation {#installation}

**Requirements:** PHP 8.3+ plus the framework pieces that provide task discovery, execution, locking, and events (`switon/core`, `switon/executor`, `switon/event`, `switon/redis`, `switon/cli`, `switon/composer-extra`).

```bash
composer require switon/schedule
```

---

## Quick Start {#quick-start}

```php
namespace App\Task;

use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

#[Scheduled('0 * * *')]
final class CleanupTask implements RunnerInterface
{
    public function run(mixed $payload): mixed
    {
        return null;
    }
}
```

Run the scheduler from the CLI:

```bash
bash bin/console schedule:list
bash bin/console schedule:run
```

---

## Core Concepts {#core-concepts}

### Tasks {#tasks}

A scheduled task is a class that implements `Switon\Core\RunnerInterface` and carries class-level `#[Scheduled]`. The scheduler invokes `run(mixed $payload)` with `null`.

### Expressions {#expressions}

Expressions use four fields: `second minute hour day-of-month`. Each field accepts only `*`, a concrete integer, or `*/n` (`*/0` is invalid). Lists, ranges, weekday, and month fields are not supported. Matching uses the app timezone.

### Locks {#locks}

`lockTtl` is per-task and bound to `1…86400` seconds (or `0`).

- `0` — no distributed lock; every replica may run the same tick.
- `1…86400` — Redis NX+EX keyed by app id and task name.

When the lock cannot be acquired, that tick is skipped without firing task events.

### Discovery {#discovery}

App tasks come from `@app/Task` and `@app/Areas/*/Task`; package tasks come from `extra.switon.tasks`. Task names are derived from the class name (drop root namespace, remove repeated leading `Task`, remove `Areas` in the second segment, strip the trailing `Task` suffix, kebab-case the dotted segments). Discovery runs during `ServiceProvider::boot()`, so classes loaded after boot are not auto-registered.

### Running {#running}

- `schedule:run` does one due-task pass and exits non-zero if any task failed; suitable for cron.
- `schedule:work` keeps ticking in a long-lived process at `tick` milliseconds; needs coroutine runtime support.
- `schedule:task <name>` runs one task immediately and skips expression matching.

---

## Configuration {#configuration}

Most apps only set discovery paths, the scheduler tick, and the Redis client for locking.

```yaml
Switon\Schedule\TaskDiscoveryInterface:
  tasks:
    '@app/Task/*Task.php': 'App\\Task\\*Task'
    '@app/Areas/*/Task/*Task.php': 'App\\Areas\\*\\Task\\*Task'

Switon\Schedule\Command\ScheduleCommand:
  tick: 1000
```

### Option Discovery {#option-discovery}

Use the current option surface directly:

```bash
bash bin/console config:options 'Switon\Schedule\TaskDiscoveryInterface'
bash bin/console config:options 'Switon\Schedule\Command\ScheduleCommand'
bash bin/console config:options 'Switon\Schedule\LockInterface'
```

---

## Keep in Mind {#keep-in-mind}

- This is a four-field expression, not crontab; no weekday, month, or per-task timezone.
- `lockTtl: 0` does not coordinate replicas; use a positive value when multiple scheduler replicas run the same task.
- The HTTP server does not auto-run schedules; run `schedule:run` (cron) or `schedule:work` (long-lived) in a separate process.
- Only the first `#[Scheduled]` on a class is used.

---

## Next {#next}

- **Common questions:** [FAQ](faq.md#schedule-faq)
- **Design trade-offs:** [Design Rationale](design.md#design-rationale)
- **Queue-backed background work:** [Queue Package](../queue/README.md)
