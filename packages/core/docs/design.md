# Design Rationale {#design-rationale}
`switon/core` is the thin layer that keeps Switon's shared vocabulary stable. It names the roles that other packages implement instead of owning their behavior.

## See also {#see-also}

- [Core Package](README.md) — contracts, conventions, and the main learning path
- [FAQ](faq.md#core-faq) — short answers for edge cases and first-run confusion
- [Kernel Package](https://github.com/switon-php/kernel/blob/main/docs/README.md) — owns executable startup ordering
- [DI Package](https://github.com/switon-php/di/blob/main/docs/README.md) — owns container behavior and lazy proxies

---

## Core Problem {#core-problem}

Framework packages need the same words for containers, app metadata, scene, paths, context, and errors. Without one shared layer, those names drift:

- one package invents its own "app object" with metadata, container access, and scene mixed together;
- another reaches into static singletons because there is no agreed contract for context;
- a third re-implements path aliasing or JSON helpers with subtly different defaults.

Core's job is to keep that vocabulary stable, not to add features on top of it.

## Primary Goal {#primary-goal}

Publish contracts and small utilities; let sibling packages provide the runtime behavior.

- **Benefit:** tests can stub Core contracts without booting the framework.
- **Benefit:** specialized packages can replace the default implementations without breaking call sites.
- **Cost:** `switon/core` alone is not a runnable application; it relies on `switon/di`, `switon/kernel`, `switon/context`, and friends.

## Core Choice {#core-choice}

Split responsibilities along clear boundaries.

- **App vs scene:** `AppInterface` owns deployment metadata; `SceneManagerInterface` owns the current runtime scene. Mixing them produces stateful "app" objects whose meaning depends on caller assumptions.
- **Shared services vs context:** services stay shared across the process; mutable per-request or per-connection state lives behind `ContextAware` plus a `ContextManagerInterface` provided by `switon/context`.
- **Attributes over DSLs:** `#[Autowired]`, `#[Scene]`, `#[ResourceAlias]`, and `#[Communal]` keep framework metadata next to the code they describe instead of in a parallel configuration file.
- **Bootstrap belongs to Kernel:** `ServiceProviderInterface` and `ContainerInterface` are Core contracts, but env loading, provider discovery, scene application, and boot order live in `switon/kernel`.

## Trade-Off {#trade-off}

Core stops at vocabulary and small utilities; it does not own runtime behavior.

- **Benefit:** the package stays small, stable, and easy to reason about across versions.
- **Cost:** documentation must say which package owns each runtime decision (DI for autowiring resolution, kernel for boot order, context for state propagation).

## Deliberate Simplicity {#deliberate-simplicity}

Markers without enforcement are intentional.

- `#[Communal]` and `ContextConnScoped` describe author intent; the default `ContextManager` does not branch on them.
- `Lazy` is an interface-shaped marker, not a wrapper class; DI is responsible for turning the union type into a proxy.
- Helpers (`Json`, `Random`, `Filesystem`, ...) stay opinionated about defaults but small in surface area, so packages can reuse them without subclassing.

That keeps Core useful as shared vocabulary without making it a runtime.
