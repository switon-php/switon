# Understand runtime and state {#runtime-and-state}

The core Switon rule behind singleton safety, request-local mutable state, and why `Context` exists.

## Terms at a glance {#terms}

If one term here feels new, keep [Glossary for beginners and translators](glossary.md#glossary) open while you read.

- **singleton service** — a shared service instance resolved from the container for the process lifetime
- **request-local state** — data that should exist only for one logical request or one coroutine scope
- **request attributes** — request-bound values carried on the HTTP request object for one request handoff
- **context** — a dedicated `*Context` object that holds mutable state for one service in one runtime scope
- **durable state** — data that should survive beyond the current request, process, or coroutine

## Why this matters in Switon {#matters-in-switon}

In a short-lived FPM-style flow, mutable properties on a service can appear harmless because the process does not stay around long enough to make the leak obvious.
In long-lived runtimes such as Swoole or worker-style CLI processes, the same service instance can handle more than one logical request.

That changes the rule:

- **shared service instances should usually stay stateless**
- **request-local mutable state should be explicit**
- **durable business data should live in real storage, not in service properties**

This is why `Context` is a first-class concept in Switon instead of an afterthought.

## Four kinds of state {#state-kinds}

Use this table as the default placement model.

| State kind | Where it usually belongs | Typical examples |
|---|---|---|
| App-wide metadata and config | `AppInterface`, `switon.yml`, env | app id, env, debug, timezone |
| Request input and request metadata | `RequestInterface`, request input objects, request attributes | route params, headers, one-request handoff values |
| Request-local mutable service state | `Context` | per-request working vars, locale state, trace state, temporary buffers that belong to one service |
| Cross-request or durable state | persistence or storage boundary | database records, uploaded files, session-backed login state, queue-backed work |

The main distinction is lifetime.
If the data must outlive the current logical request, it does not belong in `Context`.
If it belongs only to the current logical request but must be shared across several service methods, `Context` is often the right owner.

## When request attributes are enough {#attributes-enough}

Do not reach for `Context` automatically.
If the value is only a one-request handoff on the HTTP boundary, request attributes are often simpler.

Request attributes are an **HTTP-only** handoff surface. For CLI, schedule, queue, or worker paths, keep the value in explicit method parameters or move request-local mutable state into `Context`.

A good default:

- use **request attributes** for simple request-bound metadata passed across HTTP stages
- use **`Context`** when one long-lived service must hold mutable state across several method calls in one logical request

That keeps the solution proportional to the problem.

## When to use Context {#context}

Use `Context` when these conditions are true:

- the data is **mutable**
- the data belongs to **one logical request or coroutine scope**
- the data belongs to **one service boundary**, not to global app config or durable storage
- storing it directly on a shared service would be unsafe or misleading

The public application-facing rule stays small:

- create a `*Context` class
- implement `Switon\Core\ContextAware` on the service
- inject `Switon\Core\ContextManagerInterface`
- implement your service `getContext()` by returning `$this->contextManager->getContext($this)`
- read and write state through that service-level `getContext()` wrapper

When child coroutines need their own temporary state instead of shared inherited state, use `Switon\Core\ContextIsolated`.

For the owner guide, use [Context](https://github.com/switon-php/context/blob/main/docs/README.md).

## Safe default rules {#safe-default-rules}

These defaults will keep most application code out of trouble:

- **Keep services stateless when possible.**
- **Do not store request-local mutable fields directly on shared service properties.**
- **Use request attributes for simple one-request handoffs.**
- **Use `Context` when one service needs request-local mutable working state across multiple methods.**
- **Use real storage when the data must survive the current request or process.**
- **Use `ContextIsolated` only when child-local state truly must not be shared.**

If you remember only one rule, remember this:

> state lifetime decides the owner
