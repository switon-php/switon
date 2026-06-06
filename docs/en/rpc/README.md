# RPC Package {#rpc-package}

`switon/rpc` sends one queue-backed request and waits for one reply. Choose it for synchronous PHP-to-PHP request-reply over the existing queue transport. Streaming, multi-reply, and public cross-language RPC stay out of scope.

**Key features:**

- **Single request-reply call:** `call()` returns one result, raises `RpcRemoteException` for a remote error, or `RpcTimeoutException` on timeout.
- **Logical method names:** `methods` maps stable names to `ClassName::method` handlers.
- **Daemon worker:** `rpc:daemon` pulls requests from the configured queue and submits handler work through the executor.
- **Protocol-edge events:** `RpcCalling`, `RpcReceived`, and `RpcDaemonFailed` trace send, receive, and daemon failures.
- **Strict reply protocol:** malformed replies raise `RpcProtocolException`.

---

## Installation {#installation}

**Requirements:** PHP 8.3+, `switon/core`, `switon/queue`, `switon/executor`, `switon/invoker`

RPC uses `switon/queue` for transport.

```bash
composer require switon/rpc
```

---

## Quick Start {#quick-start}

Inject `Switon\Rpc\RpcInterface`, call one handler, and run a worker on the other side.

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Rpc\RpcInterface;

final class UserGateway
{
    #[Autowired] protected RpcInterface $rpc;

    public function profile(int $userId): array
    {
        return $this->rpc->call(
            UserProfileService::class . '::profile',
            ['userId' => $userId],
        );
    }
}

final class UserProfileService
{
    public function profile(int $userId): array
    {
        return ['id' => $userId];
    }
}
```

Run one worker:

```bash
bash bin/console rpc:daemon
```

---

## Core Concepts {#core-concepts}

### Call {#call}

`call($method, $params, $timeout = 3)` sends one request envelope, waits for the matching reply, and returns the `result`. `timeout` is in seconds and must be positive.

- `result` becomes the return value
- remote `error` becomes `RpcRemoteException`
- malformed replies become `RpcProtocolException`
- no matching reply within the timeout becomes `RpcTimeoutException`

### Method Resolution {#method-resolution}

Direct calls use `ClassName::method`. The `methods` map turns a logical name into that handler string. The worker resolves the final handler through the container and invoker.

### Daemon {#daemon}

`rpc:daemon` runs a long-lived pull loop:

- pulls one request envelope from the configured queue
- dispatches `RpcReceived`
- submits handler work to the executor with `concurrency`
- sends a reply or error reply with the same `correlation_id`

Each caller process owns one reply queue named `.rpc.reply.{uuid}` (dot-prefixed, so it skips queue stats). `correlation_id` is a process-local monotonic string; replies may arrive out of order and `call()` buffers them by id.

### Reply Contract {#reply-contract}

A reply body must contain either `result` or `error`. `error` is serialized as `{ class, message, code }`, and callers treat non-integer `code` values as `0`.

---

## Configuration {#configuration}

Most apps only set three options:

```yaml
Switon\Rpc\RpcInterface:
  queue: rpc
  concurrency: 8192
  methods:
    user.profile: App\Service\UserProfileService::profile
```

- `queue` is the request queue name
- `concurrency` is the executor concurrency used by `daemon()`
- `methods` maps logical names to handler strings
- reply handling uses the transport bound to `Switon\Queue\QueueInterface`

### Option Discovery {#option-discovery}

Use the current option surface directly:

```bash
bash bin/console config:options 'Switon\Rpc\RpcInterface'
```

---

## Keep in Mind {#keep-in-mind}

- `call()` is synchronous and blocks until the reply or timeout.
- This is queue-backed, not HTTP-backed; both sides are PHP and share types.
- `rpc:daemon` is the normal worker entrypoint; reply transport reuses the queue bound to `Switon\Queue\QueueInterface`.

---

## Next {#next}

- **Common questions:** [FAQ](faq.md#rpc-faq)
- **Design trade-offs:** [Design Rationale](design.md#design-rationale)
- **Queue transport:** [Queue Package](../queue/README.md)
- **Executor behavior:** [Executor Package](../executor/README.md)
