# Design Rationale {#design-rationale}

`switon/invoker` adds guards and interceptors around the DI invoker so managed method calls keep the callable path and
the hook path separate.

## See also {#see-also}

- [Invoker Package](README.md) — usage, boundaries, and configuration
- [FAQ](faq.md#invoker-faq) — short answers and troubleshooting
- [Invocation Package](../../invocation/docs/README.md) — guard and interceptor contracts

## Core Problem {#core-problem}

Dispatch code usually mixes two different jobs:

- building the final argument list from transport input or builders
- wrapping callable execution with guards and interceptors while DI still handles fallback resolution

If one package owns both, the boundary becomes hard to explain and harder to reuse across HTTP, CLI, RPC, and custom
dispatch.

## Primary Goal {#primary-goal}

Invoker standardizes method-level hooks around the DI callable execution step.

- **Benefit:** different transports share the same final invocation path.
- **Benefit:** guards and interceptors stay attached to invocation, not to transport parsing.
- **Cost:** argument construction belongs to a separate package.

## Core Choices {#core-choices}

- **Callable boundary first:** the call site already knows the target before invocation starts.
- **Small last-step resolution:** explicit overrides, defaults, type-keyed objects, and DI fallback stay in one place.
- **Hook scope:** guards and interceptors belong to managed invocation, not to plain method calls or constructor calls.

## Trade-Offs {#trade-offs}

- direct calls stay simpler, but they bypass hooks
- transport binding moves out, but the invocation path stays easier to reason about
- constructors are not treated as managed invocation targets
