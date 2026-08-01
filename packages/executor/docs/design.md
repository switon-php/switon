# Design Rationale {#design-rationale}

`Executor` standardizes one runner boundary across serial and coroutine runtimes. It keeps task identity, batch results, and lifecycle visibility in one contract while leaving transport, scheduling, and supervision outside.

## See also {#see-also}

- [Executor Package](README.md) — usage, runtime behavior, and exceptions
- [FAQ](faq.md#executor-faq) — task ids, runtime mode, and troubleshooting

---

## Why This Exists {#why-this-exists}

Applications often run the same work from different triggers, but each trigger can invent its own execution semantics.

- one path routes by class name
- one path treats partial failure differently
- one path exposes no result handle
- one path observes only the trigger edge, not the actual run boundary

`Executor` targets that drift first.

## What Switon Standardizes {#what-switon-standardizes}

The package makes these entry points converge:

- `ExecutorInterface`
- `FutureInterface`
- `Switon\Executor\Event\RunnerStarting`
- `Switon\Executor\Event\RunnerFinished`

That keeps execution behavior stable even when the runtime changes.

## What Stays Outside {#what-stays-outside}

`Executor` does not own durable queues, retries, process supervision, or transport policy.

Those concerns stay with the application or with other packages. The framework only standardizes the run boundary and the
caller-facing API.

## Why the Task Split Exists {#why-the-task-split-exists}

The segment before `:` resolves the runner service. The full string stays the `submit()` gate key.

That lets one runner service back multiple named task streams without forcing one service binding per stream.

## Why There Are Two Batch Modes {#why-there-are-two-batch-modes}

`invokeAll()` keeps one future per input slot, so every outcome stays visible.

`invokeAny()` optimizes for first success, so failed attempts stay as skipped attempts instead of terminal errors.

## Why Events Wrap the Run Boundary {#why-events-wrap-the-run-boundary}

Observability belongs around `RunnerInterface::run()`, not only around the outer trigger.

The event pair gives other packages one execution-level lifecycle stream without duplicating run timing logic.
