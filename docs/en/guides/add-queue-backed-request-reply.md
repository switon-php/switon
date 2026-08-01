# Add queue-backed request/reply {#add-queue-backed-request-reply}

Use this guide when one proven service boundary or durable background path must now wait for exactly one reply from a worker without introducing a separate HTTP API.

## Choose the right request/reply boundary {#reply-boundary}

Start with the boundary that matches what the caller really needs.

| Boundary | Best when | Keep in mind |
|---|---|---|
| [Executor](../../packages/executor/docs/README.md) | the caller wants runner execution in the current runtime without durable transport | strong for shared `RunnerInterface` execution, not for queue-backed reply contracts |
| [Queue](../../packages/queue/docs/README.md) | the caller can continue after enqueue and does not need a reply now | best default when durable one-way delivery matters more than immediate completion |
| [RPC](../../packages/rpc/docs/README.md) | the caller must wait for exactly one worker reply | keeps queue transport underneath, but adds timeout, reply matching, and remote-failure semantics |
| [HTTP](../../packages/http/docs/README.md) | callers are browsers, external systems, or other language stacks | better fit when the protocol boundary is no longer queue-native PHP-to-PHP communication |
| [Redis](../../packages/redis/docs/README.md) | reply traffic now needs separate clients, databases, or pool policy | tune this only when the backing-client boundary is the real problem |

One common application shape is:

- **app service** calls `RpcInterface::call()`
- **Queue** carries the request envelope to the worker side and the reply envelope back to the caller side
- **`rpc:daemon`** runs the long-lived receive/reply loop for that queue
- **worker method** runs inside that daemon path, returns data or throws, and the caller sees one result or one typed RPC failure

In other words: the caller pushes one request envelope, `rpc:daemon` pulls it and invokes the target method, then the daemon writes one reply envelope that the caller waits on until success, remote failure, or timeout.

## Recommended build order {#build-order}

### 1. Start after the service boundary is already correct {#build-service}

RPC should wrap work you already trust.
If the business rule still lives directly in controllers, scripts, or queue-only handler glue, fix that boundary first.

### 2. Choose RPC only when the caller really must wait {#build-choice}

If enqueue-and-continue is still acceptable, stay on [Add durable background work](add-durable-background-work.md#add-durable-background-work).
RPC is the stronger contract because the caller now depends on one reply or one timeout.

### 3. Start with one direct `ClassName::method` target {#build-direct-method}

The first reply path should stay obvious.
Use one container-resolved class and one public method before you introduce logical `methods` aliases.

### 4. Keep one queue and one daemon path visible {#build-daemon}

Start with the default `rpc` queue unless you can explain a different name in one sentence.
Run one visible `rpc:daemon` loop so the first caller and worker path is easy to trace.

### 5. Verify success, remote failure, and timeout separately {#build-verify}

Before you add more methods, confirm three outcomes:
- one normal call returns the expected result
- one worker-side exception becomes `Switon\Rpc\Exception\RpcRemoteException`
- one missing worker or mismatched queue produces `Switon\Rpc\Exception\RpcTimeoutException`

### 6. Add logical names only when caller stability matters {#build-alias}

Direct method strings are the clearest first setup.
Add `methods` mappings later when many callers should stay stable after handler refactors.

### 7. Stop before broader protocol or platform design {#build-stop}

The first RPC win does **not** require streaming, cross-language contracts, or service discovery.
Make one queue-backed reply path trustworthy before you widen the system.

## Minimal wiring {#minimal-wiring}

Use short code only to show the seam between the waiting caller, the queue-backed worker loop, and the reply result.

Application-level names like `BillingGateway`, `InvoiceService`, and `billing.invoice.show` below are illustrative. The stable part is the Switon `RpcInterface` / queue / executor boundary.

### Caller waits through `RpcInterface` {#wiring-caller}

Keep the waiting boundary explicit in application code:

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Rpc\RpcInterface;

final class BillingGateway
{
    #[Autowired] protected RpcInterface $rpc;

    public function fetchInvoice(int $invoiceId): array
    {
        return $this->rpc->call(InvoiceService::class . '::show', ['invoiceId' => $invoiceId], 3);
    }
}
```

### Worker handler stays a normal service method {#wiring-handler}

The application handler does **not** need to become a queued `RunnerInterface` job just because the caller now waits for a reply:

```php
namespace App\Service;

final class InvoiceService
{
    public function show(int $invoiceId): array
    {
        return ['id' => $invoiceId, 'status' => 'paid'];
    }
}
```

### Keep RPC config small {#wiring-config}

Start with the queue name and daemon submit concurrency in `switon.yml`:

```yaml
# switon.yml
Switon\Rpc\RpcInterface:
  queue: rpc
  concurrency: 256
```

Add `methods` later only when you want stable logical names such as `billing.invoice.show` instead of exposing `App\Service\InvoiceService::show` at every call site.

### Run one visible daemon {#wiring-daemon}

From the app root, keep the first worker path obvious:

```bash
bash bin/console rpc:daemon
```

If your project does not provide `bin/console`, follow the fallback entry shape in [CLI Quick Start](../../packages/cli/docs/README.md#fallback-entry).

The first success line is small:

- one caller waits for one result through `RpcInterface`
- one daemon loop receives requests from the configured RPC queue
- one handler return becomes a result, and worker-side failures surface as `Switon\Rpc\Exception\RpcRemoteException`, `Switon\Rpc\Exception\RpcTimeoutException`, or `Switon\Rpc\Exception\RpcProtocolException`

## What to avoid first {#pitfalls}

Keep the first request/reply path intentionally narrow.

Avoid these too early:

- **Using RPC when the caller could continue after enqueue** — stay on [Add durable background work](add-durable-background-work.md#add-durable-background-work) for one-way delivery
- **Starting callers before a daemon reads the same queue** — no worker means the caller eventually times out
- **Hiding the first contract behind aliases too early** — start with one direct `ClassName::method` target before you add `methods`
- **Treating timeout as proof the worker never ran** — `Switon\Rpc\Exception\RpcTimeoutException` only proves the caller did not receive a matching reply in time
- **Using RPC as a public API surface** — move to [HTTP](../../packages/http/docs/README.md) when browsers, external services, or other language stacks are the real boundary
- **Designing streaming or service discovery before one reply path is trustworthy** — keep the first queue-backed reply flow readable and testable

A beginner should feel this after finishing the first reply path:

> I know how one proven service becomes queue-backed request/reply without turning the first worker flow into a larger protocol platform.

## Go deeper {#go-deeper}

- **To start from one-way durable handoff before the caller waits:** [Add durable background work](add-durable-background-work.md#add-durable-background-work) · [Queue](../../packages/queue/docs/README.md)
- **To go deeper on reply protocol, aliases, and failure surfaces:** [RPC](../../packages/rpc/docs/README.md)
- **To understand the transport and execution layers underneath:** [Queue](../../packages/queue/docs/README.md) · [Executor](../../packages/executor/docs/README.md)
- **To expose browser-facing or public request/response instead:** [HTTP](../../packages/http/docs/README.md)
- **To tune Redis clients, pools, or key-space policy:** [Redis](../../packages/redis/docs/README.md) · [DI](../../packages/di/docs/README.md#quick-start)
