# Design Rationale {#design-rationale}
`switon/langfuse` maps events the framework already dispatches onto OpenTelemetry spans, and nothing else.

## See also {#see-also}

- [Langfuse Package](README.md#langfuse-package) — usage, configuration, exported shapes
- [FAQ](faq.md#langfuse-faq) — common questions

---

## Core Problem {#core-problem}

Observability drifts two ways: instrumentation crawls into business code, and trace identity is invented per integration so a conversation, its sub-agents, and its tools cannot be read as one unit.

The framework already emits `CompletionResponded`, `CompletionFailed`, `ToolFinished`, and `TurnSuspended`. The component's whole job is mapping those onto Langfuse shapes with the domain's own identity: `Thread` = session, `Turn` = trace, `Completion` = generation, `ToolCall` = span.

## Why OTLP {#why-otlp}

The legacy `POST /api/public/ingestion` path is gone: Cloud shut it down for non-score events, and self-hosted v4 rejects those events in its default write mode. `POST /api/public/otel/v1/traces` is served by both v3 and v4, so there is no version switch and no dual path.

## One trace root, re-sent {#trace-root}

OTLP has no trace record — a trace is the set of spans sharing a `traceId`.

Langfuse treats a parentless span as the trace root and folds its observation metadata into trace metadata. With every generation at the root, the last write wins and its `step`, `finish_reason`, and tool ids leak onto the trace.

So one explicit `turn` span sits above the observations and is re-sent on every export under the same span id. OTLP upserts on span id, so the root widens to the whole turn instead of duplicating.

**A resend must repeat input and output.** Langfuse applies the last write per span wholesale: a later write with the same span id and no `langfuse.observation.input`/`output` erases both, on the observation and on the trace. The root therefore derives its dialogue from the turn it belongs to, never from the triggering event.

The root writes only the turn's **text** reply, because several exports run per turn and a per-step value would make trace output depend on arrival order. A finished reply never changes; a tool-call-only step is skipped.

## Names are an API {#names}

Langfuse documents observation names as durable identifiers: evaluators target them, dashboards filter on them, saved views match them. A name embedding reply text breaks all three.

Generations are named for the action (`call-tool`, `answer-with-tool-result`, `answer`) with text kept in `output`; a tool span reads `tool · caption`. The trace name prefers the catalog profile over the per-conversation thread UUID, since the profile is the stable task identity.

## The framework shape is the trace shape {#shape}

Each `Completion::$step` becomes an `agent` span, re-sent under one span id so it upserts and its bounds widen across its children (see [Trace shape](README.md#shape)).

- `agent`, not `span`: the step orchestrates work, which is what puts it in Langfuse's agent graph.
- Tools are siblings of the generation, not children: nesting claims the generation owned the tool's wall-clock time.
- A dispatched sub-agent is typed `agent`, not `tool`, so its own generations and tools stay visible.

## Exports are queued {#export-queue}

Under coroutine hooks curl yields, so exports for one trace arrive out of order and Langfuse resolves competing writes by span end time — a 12-step trace came back holding only steps 1, 6, 9, 11. Sequential sending does not lose spans; it is the interleaving the agent loop produces that does.

`ExportQueue` restores FIFO order through one consumer coroutine. Consequences documented at [queue behaviour](README.md#queue): drain before teardown, `Termination` stops intake without waiting, and the worker exits when idle so a caller that never shuts down can still let `Coroutine\run()` return.

## Boundaries {#boundaries}

**Serialization is separate from mapping.** `Otlp` owns hex ids, nanosecond timestamps, and attribute value types; `LlmListener` owns event-to-observation mapping; `Span` is a plain data carrier.

**Attributes are strings on the wire.** `langfuse.*.metadata.*` values are flat and filterable, so nested metadata is JSON-encoded through `Json::stringify()` rather than sent nested.

**Trace attributes ride on every span.** Langfuse filters per observation, so `langfuse.session.id` and `langfuse.trace.name` repeat rather than relying on a root span to carry them.

## Known limits {#known-limits}

- **No batching.** Each LLM or tool call sends its own request, so a crash loses nothing queued and the client stays stateless.
- **No retry.** A failed export is logged and dropped; an observability queue that outlives the request is a different component.
- **Float seconds.** The framework clock is decimal seconds, so `Otlp::unixNano()` rounds to microseconds rather than printing digits the input never had.
