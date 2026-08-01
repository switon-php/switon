---
description: Switon framework highlights for identity-aware safety, named autowiring slots, and runtime-safe state handling.
---

# Switon highlights {#switon-highlights}

Read this section for the framework-level patterns that make Switon feel different in day-to-day application work.

**Key features:**

- **Framework differentiators** — explain the recurring application problems Switon standardizes instead of leaving as local team discipline.
- **Short routes to the real value** — show why a mechanism matters before repeating package reference details.
- **Cross-package patterns** — cover ideas that span `http`, `di`, `context`, `core`, and ORM-facing application code.
- **Stable deep-link targets** — give homepage and external feature blurbs one canonical landing per topic.

## Why this section exists {#purpose}

Some Switon behavior matters less because of one class or attribute and more because several packages line up around one application problem. Use highlights when the next question is not "what option key does this package support?" but "why does Switon solve this problem this way?"

---

## Current highlights {#current-highlights}

- **[Identity-aware ownership safety](identity-aware-ownership.md#identity-aware-ownership)** — how `IdentityFilter`, entity binding, and one `IdentityInterface` help keep both list queries and single-record actions inside the same ownership boundary
- **[Autowired named slots](autowired-aliases.md#autowired-aliases)** — how `#[Autowired]`, property-name slots, and `Type#name` service IDs keep multi-instance dependency wiring out of business code
- **[Context-aware runtime state](context-aware-runtime-state.md#context-aware-runtime-state)** — how `ContextAware` and `ContextManagerInterface` keep mutable runtime state safe across FPM, Swoole, and worker-style runtimes
- **[Identity as a framework contract](identity-as-a-framework-contract.md#identity-as-a-framework-contract)** — why resolved identity is a shared consumption-side contract instead of an auth detail hidden inside one package
- **[Typed input binding](typed-input-binding.md#typed-input-binding)** — how request input becomes a typed, validated action boundary instead of repeated manual parsing
- **[One app model across runtimes](one-app-model-across-runtimes.md#one-app-model-across-runtimes)** — how `Kernel`, shared services, and thin entry boundaries keep HTTP, CLI, scheduled work, and workers on one application shape
- **[Small explicit packages](small-explicit-packages.md#small-explicit-packages)** — why Switon prefers clear package boundaries and composition over one hidden runtime box

---

## How to use highlights {#highlights}

Use highlights as a bridge layer: start here from the homepage or a short feature summary, switch to the owner package docs for setup and exact contracts, then come back when you want the higher-level trade-off again.

---

## See also {#see-also}

- [Switon](../README.md) — documentation home
- [Switon fundamentals](../concepts/README.md#switon-fundamentals) — framework model and placement decisions
- [HTTP Package](../../packages/http/docs/README.md) — request, controller, and input boundaries
- [DI Package](../../packages/di/docs/README.md) — dependency injection and named services
- [Context Package](../../packages/context/docs/README.md) — context objects and runtime-scoped mutable state
