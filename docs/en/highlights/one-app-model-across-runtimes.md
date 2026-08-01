# One app model across runtimes {#one-app-model-across-runtimes}

Switon keeps one application shape across `HTTP`, `CLI`, scheduled tasks, and worker-style runtime paths:

- `Switon\Kernel\Kernel` bootstraps once
- the container owns shared services
- entrypoints stay thin
- business work stays reusable

**Why this is a highlight:**
- **One bootstrap base** — `Switon\Http\Kernel` and `Switon\Cli\Kernel` both extend `Switon\Kernel\Kernel` instead of inventing separate startup models
- **Thin runtime boundaries** — controllers, commands, scheduled tasks, and worker loops are entrypoints, not the place where business rules should accumulate
- **Shared service layer** — the same application service can be reused from a request handler, a console command, a scheduled task, or a worker path
- **Stable worker handoff** — recurring and background work can stay on `Switon\Core\RunnerInterface` and `Switon\Executor\ExecutorInterface` instead of growing one execution model per runtime

## The scattered problem {#the-scattered-problem}

Many apps slowly split into separate shapes:

- HTTP gets one service style
- CLI commands grow their own logic
- scheduled work becomes wrapper scripts
- workers reimplement the same business rules behind queue-specific code

That makes application behavior harder to test, trace, and change because each runtime stops looking like the same app.

## The Switon pattern {#the-switon-pattern}

Switon keeps the common shape smaller:

- `Switon\Kernel\Kernel` prepares env, config, DI, and fatal handling
- a runtime boundary takes over, such as `Switon\Http\Kernel`, `Switon\Cli\Kernel`, `#[Scheduled]` task execution, or a worker loop using `Switon\Queue\QueueInterface`
- that boundary delegates quickly to shared application services or to `Switon\Core\RunnerInterface` implementations

The important point is not that every runtime uses the same API surface.
The important point is that they reuse the same bootstrap contract and the same service layer.

## What stays reusable {#what-stays-reusable}

This model keeps runtime-specific code narrow:

- HTTP boundaries focus on request input and response output
- CLI boundaries focus on console input and operator feedback
- scheduled tasks focus on time-based triggering
- worker loops focus on pulling and consuming work

The business operation itself can stay in one service, or in one `RunnerInterface` path when the job is naturally runner-shaped.

## Boundaries {#boundaries}

This does **not** mean every runtime should be forced into one identical abstraction.

- Use `Switon\Http\Kernel` for HTTP serving concerns.
- Use `Switon\Cli\Kernel` for command entry.
- Use `switon/schedule` when time rules are the real trigger.
- Use `switon/queue`, `switon/rpc`, or worker loops only when background transport or reply semantics are the real boundary.

The shared model is about keeping one app structure underneath those runtime differences, not erasing the differences.

## See also {#see-also}

- **To place this in the framework mental model:** [Understand the application model](../concepts/application-model.md#application-model)
- **To decide what should live in controllers, commands, tasks, and workers:** [Understand service boundaries](../concepts/service-boundaries.md#service-boundaries)
- **To understand singleton safety across long-lived runtimes:** [Understand runtime and state](../concepts/runtime-and-state.md#runtime-and-state)
- **To start from first proof before choosing a runtime path:** [Getting started](../getting-started/README.md#getting-started)
- **To go deeper on shared bootstrap:** [Kernel Package](https://github.com/switon-php/kernel/blob/main/docs/README.md)
- **To add a manual runtime entrypoint:** [CLI Package](https://github.com/switon-php/cli/blob/main/docs/README.md) and [Add CLI](../guides/add-cli.md#add-cli)
- **To add recurring runtime entry:** [Schedule Package](https://github.com/switon-php/schedule/blob/main/docs/README.md) and [Add recurring work](../guides/add-recurring-work.md#add-recurring-work)
- **To add worker-style durable background work:** [Queue Package](https://github.com/switon-php/queue/blob/main/docs/README.md) and [Add durable background work](../guides/add-durable-background-work.md#add-durable-background-work)
