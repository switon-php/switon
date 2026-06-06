# Understand service boundaries {#service-boundaries}

Where application code should live before the project grows across HTTP, CLI, recurring work, and background workers.

## Terms at a glance {#terms}

If one term here feels new, keep [Glossary for beginners and translators](glossary.md#glossary) open while you read.

- **transport boundary** — the outer entry such as HTTP, CLI, schedule, queue, or RPC
- **service** — the reusable business-logic boundary
- **lifecycle listener** — request-wide HTTP logic that should run before or after controller invocation
- **event listener** — an in-process reaction to a typed event after the main business action emits it
- **worker** — a long-lived process, often run or supervised outside HTTP, that handles background work outside the request lifecycle

## One rule first {#one-rule-first}

If a piece of code should work from more than one entrypoint, it should usually **not** live in a controller action, console command, or worker loop.
It should live in a service.

That gives you a stable split:

- **controllers, commands, and workers** receive input and coordinate the transport/runtime concern
- **services** own business decisions and business-side orchestration
- **listeners** own cross-cutting reactions or request-wide policy

Once that split is clear, most guide pages become easier to read because each layer is solving a smaller problem.

## Put code in the narrowest owner {#code-narrowest-owner}

Use this table as the default placement rule.

| Concern | Best owner | Why |
|---|---|---|
| URL path, verb, status code, redirect, download | controller / HTTP boundary | these are transport decisions |
| request payload shape and request-boundary validation | request input object + validation | input should fail at the boundary |
| business rules, persistence orchestration, mail decisions, reusable workflows | service | the same logic should be callable from more than one transport |
| request-wide auth loading, request ids, CORS, access logs | lifecycle listener | these concerns apply across many requests |
| action-only wrapping behavior | controller interceptor | the behavior is local to one action or controller |
| in-process follow-up reactions such as audit hooks or notification triggers | event listener | the reaction should stay decoupled from the main path |
| manual operator entry, timed entry, durable worker entry | CLI command / schedule / queue / RPC worker | these are entrypoint concerns, not the business model itself |

For the common HTTP split: a lifecycle listener is request-wide, a controller interceptor is local to one action or controller, and an event listener reacts after application code emits a typed event.

When in doubt, ask which layer would still make sense if HTTP disappeared tomorrow.
If the business capability still exists, it probably belongs in a service.

## One business action can have many entrypoints {#business-many-entrypoints}

A healthy Switon app often reuses the same service through multiple entry paths.

For example, the same “generate invoices” capability might be reached by:

- an HTTP controller action for an admin-triggered run
- a CLI command for operators
- a scheduled task for nightly processing
- a queue worker for durable background processing

Those entrypoints should stay thin. Their job is to adapt input, runtime, or delivery shape.
The business rule itself should not be rewritten four times.

This is why guides such as [Add CLI](../guides/add-cli.md#add-cli), [Add recurring work](../guides/add-recurring-work.md#add-recurring-work), and [Add durable background work](../guides/add-durable-background-work.md#add-durable-background-work) all make more sense after the service boundary is already clean.

## DI keeps the boundaries honest {#di-boundaries-honest}

Dependency injection helps these boundaries stay visible.

In normal application code:

- depend on interfaces or stable package contracts
- let `#[Autowired]` declare the dependency
- avoid container lookup as the normal business-code style

That keeps business code explicit about what it needs and makes transport boundaries thinner.
If a controller or worker needs many unrelated low-level services plus request parsing and response shaping, the boundary is usually too wide.

Use [DI](../di/README.md) for the owner guide.

## Common boundary mistakes {#common-boundary-mistakes}

Watch for these common drifts:

- **controllers turning into service classes** — route handling, validation, persistence, and mail delivery all mixed into one action
- **services parsing HTTP details** — header, cookie, or response decisions leaking into reusable business code
- **lifecycle listeners owning business workflows** — request-wide hooks doing domain work that should be callable outside HTTP
- **commands or workers re-implementing controller business logic** — transport entrypoints duplicating service behavior instead of reusing it

The fix is usually not more framework code.
It is usually moving the concern back to the right owner.
