# Design Rationale {#design-rationale}
`switon/queue` keeps transport, discovery, delayed migration, and execution separate.

## See also {#see-also}

- [Queue Package](README.md) — usage, envelopes, and worker entrypoints
- [Queue FAQ](faq.md#queue-faq) — short answers and common behavior

---

## Core Problem {#core-problem}

Queue systems drift when one layer tries to own everything.

- transport starts to hide worker policy
- job discovery starts to look like a runtime registry
- delayed delivery becomes a side effect
- retry and failure policy disappear into transport code

This package keeps the handoff form visible.

## Why The Split Exists {#split}

- `Queue` moves envelopes through Redis.
- `JobDiscovery` resolves queue names to runner classes.
- `migrateDue()` makes delayed work ready on an explicit schedule.
- `consume()` delegates execution to the executor layer.

That split keeps each responsibility smaller and easier to replace.

## Why Discovery Is Strict {#discovery-strict}

One queue name maps to one runner class.

That keeps delivery predictable and avoids competing handler lists.
The `concurrency` field stays on discovery, where it can select the executor path without turning the queue into worker supervision.

## Why Delayed Migration Is Explicit {#delayed-migration-explicit}

Delayed messages sit in Redis until a worker or migrator calls `migrateDue()`.

That makes delayed delivery operationally visible and keeps consumer latency separate from scheduling work.

## What The Package Deliberately Does Not Own {#outside}

It does not own:

- retry policy
- dead-letter strategy
- exactly-once delivery
- queue-level throttling
- worker orchestration beyond the shipped CLI entrypoints

Those decisions stay with the application and deployment topology.
