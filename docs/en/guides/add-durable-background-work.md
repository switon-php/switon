# Add durable background work {#add-durable-background-work}

Use this guide when one existing service, command, or recurring task should now hand work to durable queue transport so producers and workers no longer need to share one process or one success window.

## Choose the right durable-work boundary {#work-boundary}

Start with the boundary that matches the delivery guarantee your application actually needs.

| Boundary | Best when | Keep in mind |
|---|---|---|
| [Executor](../executor/README.md) | the caller wants lightweight runner execution in the current runtime, not durable transport | strongest for in-process execution and lifecycle hooks, not for surviving worker/process failure |
| [Queue](../queue/README.md) | one-way work should survive process boundaries and be consumed by worker loops later | best default when transport durability matters more than immediate completion |
| [RPC](../rpc/README.md) | the caller must wait for one reply from a worker | built on queue transport, but adds a stricter reply contract and timeout surface |
| [Schedule](../schedule/README.md) | time rules decide **when** work starts, but the actual handoff may still need durability | pair with queue when a recurring producer should enqueue work instead of finishing it inline |
| [Redis](../redis/README.md) | queue traffic now needs separate clients, databases, or pool policy | tune this only when the backing-client boundary is the real problem |

One common application shape is:

- **HTTP / CLI / Schedule** to produce one small unit of work
- **Queue** to store the transport envelope durably
- **Executor** to run the resolved `RunnerInterface` job
- **RPC** only when the producer must block for a response

## Recommended build order {#build-order}

### 1. Start after the producer path is already correct {#build-producer}

Queueing should wrap business logic you already trust.
If the work still lives directly in controllers, shell-only scripts, or ad-hoc callbacks, fix that boundary first.

### 2. Choose one logical queue and one runner boundary {#build-queue-name}

Start with one queue name you can explain in one sentence.
Here "queue name" means the logical queue identifier your producer pushes to and your worker pulls from, not the raw Redis key layout.
One logical queue should map to one runner class, so make the first producer/consumer path obvious before you widen throughput or split routing.

### 3. Enqueue transport data, not live services {#build-envelope}

The producer should push one small payload that the worker can understand later.
Keep service resolution in the container and execution in the worker, not inside serialized objects or ad-hoc closures.
That keeps queue payloads small, stable, and safe to deserialize later instead of trying to serialize live service instances, connections, or runtime state.

### 4. Keep delayed delivery explicit {#build-delay}

If you use delayed messages, remember that `pull()` does **not** move them automatically.
Call `migrateDue()` in the worker loop that owns delayed migration, or keep delays out of the first queue path.

### 5. Verify one enqueue, one consume, and one stats read {#build-verify}

Before you add more queues, confirm three things:
- the producer can push one envelope successfully
- one worker pass can `pull()` and `consume()` the expected queue
- `stats('<queue>')` (see [Queue stats](../queue/README.md#stats)) or your operator-facing queue probe shows that you are looking at the right logical queue

### 6. Add idempotency and minimal failure visibility before scale {#build-idempotency}

The first durable path needs two habits early: make handler logic safe to replay, and log enough information to see one success or one failure.
Do that before you design retries, worker fleets, or richer metrics.

### 7. Stop before reply protocols or worker-platform design {#build-stop}

The first queue win does **not** require RPC aliases, supervisor design, or dead-letter architecture.
Make one durable path trustworthy before you widen the system.

## Minimal wiring {#minimal-wiring}

Use short code only to show the seam between the producer, the queue transport, and the queued runner.

Application-level names like `WelcomeEmailServiceInterface`, `UserRegistrationService`, and `SendWelcomeEmailJob` below are illustrative. The stable part is the Switon queue and runner boundary.

### Shared application service stays unchanged {#wiring-service}

Keep the business action behind one application boundary:

```php
namespace App\Service;

interface WelcomeEmailServiceInterface
{
    public function sendForUser(int $userId): void;
}
```

### Producer hands off one envelope {#wiring-producer}

When registration succeeds, push one transport payload instead of sending mail inline:

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Queue\QueueEnvelope;
use Switon\Queue\QueueInterface;

final class UserRegistrationService
{
    #[Autowired] protected QueueInterface $queue;

    public function register(int $userId): void
    {
        // persist the user first...
        $this->queue->push('emails', QueueEnvelope::of(['userId' => $userId]));
    }
}
```

### One queued runner owns the worker boundary {#wiring-job}

Use [Queue](../queue/README.md) to map the logical queue to one runner class:

```php
namespace App\Job;

use App\Service\WelcomeEmailServiceInterface;
use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;
use Switon\Queue\QueueEnvelope;

#[Queued('emails')]
final class SendWelcomeEmailJob implements RunnerInterface
{
    #[Autowired] protected WelcomeEmailServiceInterface $mailer;
    #[Autowired] protected LoggerInterface $logger;

    public function run(mixed $payload): mixed
    {
        /** @var QueueEnvelope $envelope */
        $envelope = $payload;
        $userId = (int) ($envelope->body['userId'] ?? 0);

        $this->mailer->sendForUser($userId);
        $this->logger->info('Sent welcome email', ['userId' => $userId]);

        return null;
    }
}
```

This keeps responsibilities clean:

- the producer decides **when the application should enqueue work**
- the queue transport decides **how the envelope is stored and pulled**
- the runner decides **how the worker executes one queued unit**

### Queue transport and discovery stay explicit {#wiring-config}

Keep the first queue path small in `switon.yml`:

```yaml
# switon.yml
Switon\Redis\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: redis://127.0.0.1:6379/0

Switon\Queue\QueueInterface:
  prefix: 'queue:'
  dueEvery: 1

Switon\Queue\JobDiscoveryInterface:
  paths:
    '@app/Job/*Job.php': 'App\Job\*Job'
```

Use the default Redis client first. `QueueInterface` is Redis-backed, so this client binding is part of the minimum working path, not an optional optimization layer.
Only split named clients later when queue traffic, cache traffic, or session traffic really need different backing policy.

### Run one explicit worker pass {#wiring-worker}

A first durable path is easier to trust when one worker pass is visible in normal app code or one small operator command:

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Queue\QueueInterface;

final class EmailQueueProbe
{
    #[Autowired] protected QueueInterface $queue;

    public function processOnce(): void
    {
        $this->queue->migrateDue('emails');
        $envelope = $this->queue->pull('emails', 1);

        if ($envelope !== null) {
            $this->queue->consume($envelope);
        }
    }
}
```

If you are not using delays yet, `migrateDue()` is harmless but not the reason the ready queue works.
The first success line is small:

- one producer no longer needs the worker to finish inline
- one logical queue maps to one runner class
- one explicit worker pass can pull, consume, and inspect the queue state

## What to avoid first {#pitfalls}

Keep the first durable path intentionally narrow.

Avoid these too early:

- **Queueing work before the producer boundary is clear** — prove the service, command, or recurring producer first
- **Treating enqueue success as proof that a job is discoverable** — the failure can still appear later at `consume()` time
- **Expecting `#[Queued(..., concurrency: N)]` to manage workers for you** — that metadata does not become a built-in supervisor or transport throttle by itself
- **Assuming delayed work appears automatically in `pull()`** — call `migrateDue()` when delays are part of the design
- **Using queueing when the caller really needs one reply** — move to [RPC](../rpc/README.md) for request/reply semantics
- **Designing retries, dead-letter routing, or exactly-once guarantees before one worker path is trustworthy** — keep the first queue win readable and testable

A beginner should feel this after finishing the first durable path:

> I know how one proven application path becomes durable background work without turning the first queue into a whole platform.

## Go deeper {#go-deeper}

- **To connect this after a recurring producer path:** [Add recurring work](add-recurring-work.md#add-recurring-work) · [Timed cleanup](../tutorial/timed-cleanup.md#timed-cleanup)
- **To use durable workers for welcome, reset, or receipt mail instead of generic job design:** [Send transactional email](send-transactional-email.md#send-transactional-email)
- **To go deeper on transport, discovery, and worker topology:** [Queue](../queue/README.md)
- **To manage runner execution and lifecycle hooks directly:** [Executor](../executor/README.md)
- **To tune Redis clients, pools, or key-space policy:** [Redis](../redis/README.md) · [DI](../di/README.md#quick-start)
- **To run durable workers alongside HTTP and scheduler processes in one go-live shape:** [Deploy an app to production](deploy-an-app-to-production.md#deploy-an-app-to-production)
- **To turn one-way delivery into request/reply:** [Add queue-backed request/reply](add-queue-backed-request-reply.md#add-queue-backed-request-reply) · [RPC](../rpc/README.md)
- **To keep time rules at the producer boundary:** [Schedule](../schedule/README.md)
- **To add lightweight failure visibility:** [Log](../log/README.md#quick-start)
