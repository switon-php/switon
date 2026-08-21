# Langfuse FAQ {#langfuse-faq}
Short answers for setup, configuration, and trace-reading issues.

> **Prerequisites:** [Langfuse Package](README.md)

## Trace reading {#trace-reading}

### Why does the trace have a `turn` span above the generations? {#why-turn-span}

Langfuse treats a parentless span as the trace root and folds its observation metadata into trace metadata; without an explicit root, the last generation written leaks `step`, `finish_reason`, and tool ids onto the trace. See [One trace root](design.md#trace-root).

### Where is the trace input/output? {#where-is-the-trace-io}

On the `turn` root span, as `langfuse.observation.input`/`output`, derived from the turn on every export. v4 reads trace-level I/O from the root observation, so the component never sends the deprecated trace fields.

### Why is a tool call two spans when it was suspended? {#why-two-tool-spans}

A suspended tool has not run yet. The suspend is written as `{callId}:suspend` so the later real run keeps the call id without colliding. Both hang under the same `step` span.

### Why does my trace id not look like the turn id? {#why-trace-id-differs}

OTLP trace ids are 32 hex characters. A UUID turn id fits once hyphens are stripped and is reused verbatim; any other id is hashed, which stays stable but unreadable.

### Why are usage numbers smaller than the provider reports? {#why-usage-is-smaller}

Langfuse requires exclusive buckets: cached input goes to `cache_read_input_tokens`, not inside `input`. When a provider reports an inclusive prompt count, `input` is reduced by the cache-read and cache-write counts so cost is not billed twice.

## Export behaviour {#export}

### Why does nothing reach Langfuse? {#why-nothing-reaches-langfuse}

`ServiceProvider::boot()` registers the listener only when `ClientInterface::baseUrl()` is non-empty. An empty host is the documented off state, so no event is exported and no warning is logged.

### Why did my credentials warning appear only once? {#why-one-credentials-warning}

`Switon\Langfuse\Client` logs it once per process to avoid flooding on every LLM call. Fix the key and restart the worker; the flag resets with the process.

### Does an export failure break the LLM call? {#does-export-failure-break-the-call}

No. `Client::ingest()` catches transport and HTTP errors and logs a warning. The reverse is not guaranteed: a malformed payload thrown from the listener would reach the caller, so keep the serializers total.

### Can it export to a v3 deployment? {#can-it-export-to-v3}

Yes. The exporter targets OTLP, served by both v3 and v4. Only the read side differs — use Observations API v2 and Metrics API v2 on v4.
