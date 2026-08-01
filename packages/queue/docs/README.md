# Queue Package {#queue-package}

`switon/queue` is a Redis-backed job queue. Jobs are `Switon\Core\RunnerInterface` classes bound to one queue name through `#[Queued]`. Choose it for asynchronous, at-least-once jobs inside one PHP application; retry policy, dead-letter strategy, and worker orchestration stay with the application.

**Key features:**

- **Envelope transport:** `QueueEnvelope` carries body, headers, queue name, and message metadata.
- **Delayed delivery:** `push()` enqueues immediately or into a delayed sorted set; `migrateDue()` makes them ready.
- **Job discovery:** `#[Queued('queue-name', concurrency: N)]` binds one runner class to one queue.
- **Worker commands:** `queue:stats`, `queue:push`, `queue:run`, `queue:work`, and `queue:migrate-due`.
- **Shard-free routing:** one queue name maps to exactly one runner definition.

---

## Installation {#installation}

**Requirements:** PHP 8.3+, `switon/core`, `switon/redis`, `switon/executor`, `switon/composer-extra`, `psr/event-dispatcher`

```bash
composer require switon/queue
```

---

## Quick Start {#quick-start}

Define one runner, push one envelope, then let the CLI worker consume it.

```php
namespace App\Job;

use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;
use Switon\Queue\QueueEnvelope;

#[Queued('emails', concurrency: 2)]
final class SendWelcomeEmailJob implements RunnerInterface
{
    /**
     * @param QueueEnvelope $payload
     */
    public function run(mixed $payload): mixed
    {
        // send email with $payload->body['userId']
        return null;
    }
}
```

```php
use Switon\Core\Attribute\Autowired;
use Switon\Queue\QueueEnvelope;
use Switon\Queue\QueueInterface;

final class UserService
{
    #[Autowired] protected QueueInterface $queue;

    public function register(int $userId): void
    {
        $this->queue->push('emails', QueueEnvelope::of(['userId' => $userId]));
    }
}
```

```bash
bash bin/console queue:work
```

---

## Core Concepts {#core-concepts}

### Queue Envelope {#queue-envelope}

`Switon\Queue\QueueEnvelope` is the transport unit. It carries `message_id`, `queue`, `timestamp`, optional protocol fields (`type`, `correlation_id`, `reply_to`, `expiration`), `body`, and `headers`.

`QueueEnvelope::of($body, $headers)` is the payload-side helper; set protocol fields directly on the envelope when you need them.

### Push and Pull {#push-pull}

`push($queue, $envelope, $delay)` stores one envelope in Redis: missing `message_id` gets a new UUID, missing `timestamp` gets the current time. `delay = 0` writes to the ready list; `delay > 0` writes to the delayed sorted set.

`pull($queues, $timeout)` reads one ready envelope. An empty `$queues` list falls back to discovered queue names. The timeout is passed to Redis `BRPOP`; `0` blocks until a job arrives. `pull()` does not migrate delayed messages.

`Switon\Queue\Event\QueuePushed` and `Switon\Queue\Event\QueuePulled` fire after each operation.

### Delayed Migration {#delayed-migration}

Delayed messages become ready only when some loop calls `migrateDue($queues)`. The call drains due messages in batches and is throttled per queue per PHP process by the `dueEvery` setting. `queue:migrate-due` is the shipped CLI loop; `pull()` itself does no migration.

### Consume {#consume}

`consume($envelope)` looks up the runner class for `$envelope->queue` and delegates execution to the executor.

- unmapped queues fail when `consume()` runs (not at `push()`)
- `concurrency = 1` uses the direct `invoke()` path; `concurrency > 1` submits to the executor
- `queue:work` accepts one queue, a comma list, or no argument (which uses discovered queues)

### Discovery {#discovery}

`Switon\Queue\JobDiscovery` builds queue definitions from two sources:

- app scan paths `@app/Job/*Job.php` and `@app/Areas/*/Job/*Job.php`
- Composer `extra.switon.jobs`

Each discovered class must implement `Switon\Core\RunnerInterface` and carry `#[Queued('queue-name')]`. Empty queue names are skipped; duplicate queue names fail discovery; only the first `#[Queued]` on a class is used.

### Stats {#stats}

`stats($queue)` returns `queue`, `prefix`, `ready`, `delayed`, `failed`, and `counter` for one queue. `failed` is read from Redis state; this transport does not write failed items itself. Queue names starting with `.` skip the counter hash.

---

## Keep in Mind {#keep-in-mind}

- `pull()` never migrates delayed jobs; some loop must call `migrateDue()`.
- `consume()` succeeds only after discovery resolves a runner for the envelope queue.
- `queue:run` uses a short one-second pull window; `queue:work` blocks when `timeout` is `0`.
- Dot-prefixed queue names (for example `.rpc.reply.{uuid}`) skip stats counters.

---

## Next {#next}

- **Common questions:** [FAQ](faq.md#queue-faq)
- **Design split:** [Design Rationale](design.md#design-rationale)
- **Parallel execution:** [Executor Package](https://github.com/switon-php/executor/blob/main/docs/README.md)
- **Redis transport:** [Redis Package](https://github.com/switon-php/redis/blob/main/docs/README.md)
