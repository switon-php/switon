# Design Rationale {#design-rationale}

`switon/context` keeps mutable runtime state out of shared services by making the owning service declare the scope boundary once.

## See also {#see-also}

- [Context Package](README.md) — usage, boundaries, and first integration
- [FAQ](faq.md#context-faq) — common questions and troubleshooting
- [Context internals](internals/README.md) — evolution notes when you need implementation rationale
- [HTTP Request](../http/request.md#request) — request attributes for one-step handoffs
- [Core Package](../core/README.md) — `ContextAware`, `ContextManagerInterface`, and `#[Communal]`

---

## Core Problem {#core-problem}

Mutable service state is one of the first places where app structure drifts.

- one service starts storing request data on itself
- another runtime exposes its own runtime-specific state model
- child coroutines share state sometimes but not always
- cleanup rules depend on hidden runtime behavior

The framework needs one state boundary that stays explicit in user code and predictable across runtimes.

## Primary Goal {#primary-goal}

Switon Context standardizes one owner-based scoped-state model first, then lets runtimes decide how that scope is stored.

- **Benefit:** service code stays reusable across FPM, Swoole, and long-lived workers.
- **Benefit:** the same API works for inherited and isolated child scopes.
- **Cost:** mutable state must go through an explicit context owner contract.

## Core Choice {#core-choice}

The package treats the service as the owner and the context object as the mutable state container.

- `ContextAware` marks the owner
- `ContextManagerInterface` resolves the active context for the current runtime scope
- `ContextIsolated` changes the child-coroutine boundary when sharing would be unsafe

That keeps application code on one stable path instead of teaching it multiple storage APIs.

## Trade-Off {#trade-off}

Switon Context does not try to be a generic app container.

- **Benefit:** ownership stays clear.
- **Benefit:** runtime behavior is easier to reason about.
- **Cost:** code that wants a quick one-step handoff still belongs to request attributes or another simpler boundary.

## Deliberate Simplicity {#deliberate-simplicity}

The package keeps the normal application path small.

- owner services call `getContext()`
- the default child scope inherits state unless marked otherwise
- observability stays separate from the main contract

That is enough for most application state without turning the package into a storage framework.
