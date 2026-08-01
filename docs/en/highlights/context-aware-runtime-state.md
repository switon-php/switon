# Context-Aware Runtime State {#context-aware-runtime-state}

`Switon\Core\ContextAware` plus `Switon\Core\ContextManagerInterface` is one of Switon's core framework judgments: mutable runtime state should be explicit, typed, and scoped to the current logical request or coroutine instead of living on shared service instances.

**Why this is a highlight:**
- **Clear state boundary** — mutable runtime state lives in a dedicated `*Context` object, not in singleton service properties
- **Container stays focused** — the DI container still resolves services; it does not become a generic request bag
- **Runtime-safe default** — the same service code stays safe across FPM, Swoole, and long-lived worker processes
- **Reusable business services** — application logic keeps one service API while the runtime state storage stays separate

## The decision Switon makes {#decision-makes}

Switon treats state lifetime as a framework boundary.

If data belongs to one logical request or coroutine and one long-lived service needs to mutate it across multiple methods, the service should implement `Switon\Core\ContextAware` and delegate storage to `Switon\Core\ContextManagerInterface`.

That avoids two common failure modes:

- putting request-local mutable data on shared service properties
- turning the container or request object into a catch-all state transport

## Why this beats a request bag {#beats-request-bag}

A request bag is easy to overuse. Once every service can push arbitrary mutable values into a shared bag, ownership becomes unclear and type boundaries disappear.

Switon keeps the boundary narrower:

- the service owns the state shape through its `*Context` class
- the runtime owns scope resolution through `ContextManagerInterface`
- the container still owns dependency resolution

This gives request-local mutability without collapsing service boundaries.

## Why it stays safe across runtimes {#safe-across-runtimes}

Under FPM, unsafe mutable service properties can look harmless because process lifetimes are short.
Under Swoole or worker-style runtimes, the same shared service instance can survive across many logical requests.

Switon standardizes the safe path instead:

- shared services stay effectively stateless at the instance-property level
- mutable runtime state moves into context objects resolved per runtime scope
- business code keeps calling the same service methods regardless of runtime model

That is why the pattern scales from FPM to coroutine and worker runtimes without changing the application-facing service design.

## When to use it {#use-it}

Use this model when all of these are true:

- the state is mutable
- the state belongs only to the current logical request or coroutine
- one service needs that state across multiple method calls

Do not use it for stateless services.
Do not use it for durable cross-request data such as database records or session-backed persistence.
For simple HTTP-only handoff values, request attributes may still be the smaller tool.

## See also {#see-also}

- **To learn the full app-facing usage model:** [Context Package](https://github.com/switon-php/context/blob/main/docs/README.md)
- **To see the Core contract boundary:** [Core Package](https://github.com/switon-php/core/blob/main/docs/README.md)
- **To place this rule in the wider runtime model:** [Understand runtime and state](../concepts/runtime-and-state.md#runtime-and-state)
