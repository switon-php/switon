# Glossary {#glossary}

Plain meanings for recurring Switon terms, especially for first-time readers and translation-assisted reading.

## How to use these terms {#terms}

Use these three rules:

- keep **one local-language term** for one English term across the docs
- if your language has no short natural equivalent, keep the English term on first mention and add a short explanation
- prefer the most practical wording, not the most formal wording

The goal is simple: help the reader act correctly without decoding the same idea again and again.

## Core terms {#core-terms}

### App startup and runtime {#startup-runtime}

| Term | Plain meaning | Keep in mind |
|---|---|---|
| **bootstrap** | the startup phase before normal app work begins | this is where env, config, and services are prepared |
| **runtime** | the channel that is currently running the app | common runtimes are HTTP, CLI, schedule, and worker |
| **scene** | the short label for the current runtime | examples: `http`, `cli`, `schedule` |
| **owner doc** | the package doc that has the full detail | guides and tutorial pages point to owner docs instead of copying them |

### Request and service flow {#request-flow}

| Term | Plain meaning | Keep in mind |
|---|---|---|
| **boundary** | the place where one job stops and another job starts | when a guide says “choose the right boundary”, read it as “put the code in the right place” |
| **transport boundary** | the outer entry that receives or sends transport-specific data | examples: controller actions, CLI commands, queue workers |
| **service** | the reusable class that owns business work | the same service should be callable from more than one entrypoint |
| **service boundary** | the point where transport code hands business work to a service | this is the split that keeps controllers and commands thin |
| **lifecycle listener** | request-wide HTTP logic that runs at a known stage | examples: request ids, CORS, auth loading, access logs |
| **controller interceptor** | wrapping logic for one controller or one action | use this for local HTTP behavior, not for whole-request policy |
| **event listener** | an in-process reaction after app code emits a typed event object such as one specific event class | use this when the follow-up reaction should stay separate from the main path |
| **request input object** | a typed input object built from request data | use it to keep request input clear and validated |
| **handoff** | the moment one layer passes the needed work or data to the next layer, usually by a method call or queue push | common handoffs are controller → service and service → queue |

### State and background work {#background-work}

| Term | Plain meaning | Keep in mind |
|---|---|---|
| **request attributes** | values attached to one HTTP request object | use them for simple HTTP-only handoffs |
| **Context** | a request-local state object for one service | use it when a shared service needs mutable state for one logical request |
| **worker** | a process that handles background work outside the normal request path | workers often run longer than one HTTP request |
| **recurring work** | work that runs by time rule | examples: nightly cleanup, hourly report generation |
| **durable background work** | work handed to a queue or transport that survives request failure or delay | use this when the job must be retried or completed later |
| **escape hatch** | the more explicit, lower-level path when the default shortcut is not enough | keep it as a tool, not as your starting point |
| **shape** | the overall form of a solution | in plain reading, “application shape” usually means the big-picture structure |
| **surface** | the part you directly work with | examples: config surface, public API surface |

## Translation tips {#translation-tips}

These words cause the most confusion in translation:

- **`boundary`** usually means **the place where one job stops and another starts**, or simply **where the code should live**, not a physical border
- **`shape`** usually means **overall structure**, not geometric shape
- **`surface`** usually means **the part the developer touches directly**, not a visual surface
- **`handoff`** usually means **pass work/data to the next layer**, not a human handover ceremony
- **`escape hatch`** usually means **a clear fallback path**, not an error state

If a sentence still feels abstract, reduce it to this question:

> what is the code supposed to do here, and where should that code live?

That question usually gives the clearest local-language rewrite.
For example, if a sentence says "move it to the service boundary", rewrite it locally as "put this reusable business code in the service layer, not in the controller".
