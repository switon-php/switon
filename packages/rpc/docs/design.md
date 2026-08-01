# Design Rationale {#design-rationale}

`switon/rpc` keeps queue-backed request-reply thin, explicit, and executor-aware.

## See also {#see-also}

- [RPC Package](README.md) — usage, configuration, and worker entrypoint
- [FAQ](faq.md#rpc-faq) — short answers for setup and common issues
- [Queue Package](https://github.com/switon-php/queue/blob/main/docs/README.md) — transport and envelopes
- [Executor Package](https://github.com/switon-php/executor/blob/main/docs/README.md) — submitted work and concurrency

---

## Core Problem {#core-problem}

Queue-based RPC can easily grow into a second transport platform. The framework only needs one queue-native request-reply boundary.

## Primary Goal {#primary-goal}

`switon/rpc` standardizes one `call()` / one reply / one timeout flow:

- request transport stays on `switon/queue`
- submitted handler work stays on the executor
- reply protocol failures stay visible

## Trade-Off {#trade-off}

- **Benefit:** callers get a small synchronous contract.
- **Benefit:** worker behavior stays aligned with queue and executor.
- **Cost:** streaming, multi-reply, and public cross-language RPC stay out of scope.

## Deliberate Limits {#deliberate-limits}

- one caller process reuses one reply queue
- `methods` only maps logical names to handler strings
- `rpc:daemon` is the normal worker loop
- events stay at the protocol edge
