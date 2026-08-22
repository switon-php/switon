# Design Rationale {#design-rationale}

`switon/invocation` keeps method hooks as attributes and contracts. It does not execute them.

## See also {#see-also}

- [Invocation Package](README.md) — usage and the public contract
- [FAQ](faq.md#invocation-faq) — common questions
- [Invoker Package](https://github.com/switon-php/invoker/blob/main/docs/README.md) — the runtime that runs these hooks

---

## Core Problem {#core-problem}

Method-level checks drift when each component invents its own before/after hook.

- One component wraps the call in a subclass.
- Another reads attributes and runs them in a different order.
- A direct method call and an invoker call silently disagree.

The framework needs one hook shape that every managed call can share.

## Primary Goal {#primary-goal}

`switon/invocation` standardizes the attributes first: a guard before the call, and an interceptor around it.

- **Benefit:** guards and interceptors look the same on every managed method.
- **Benefit:** the execution policy lives in one invoker, not in each attribute.
- **Cost:** hooks do nothing until a caller goes through that invoker.

## Core Choice {#core-choice}

The package separates the contract from the call.

- **Guards** run first. Throwing blocks the method.
- **Interceptors** then get `preHandle`, `postHandle`, and `onException`.
- **The base interceptor** supplies no-op hooks so a caller can implement one of them.
- **Argument binding** stays in [binding](https://github.com/switon-php/binding/blob/main/docs/README.md), not here.

Throwing is the only way to stop the call. A `false` return does not skip it.

## Trade-Off {#trade-off}

The package ships interfaces and a no-op base class, not a runner.

- **Benefit:** HTTP, CLI, and other invokers can share one hook model.
- **Cost:** a plain method call bypasses every guard and interceptor.
