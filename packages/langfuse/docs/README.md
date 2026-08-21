# Langfuse Package {#langfuse-package}
`switon/langfuse` exports Switon LLM traffic to Langfuse as OpenTelemetry spans. Choose it when you want conversation traces, per-call token usage, and tool spans without instrumentation calls in business code — it listens to the events `switon/llm`, `switon/ai`, and `switon/tooling` already dispatch.

**Key features:**

- **One trace per turn:** a turn id becomes the OTLP trace id; sub-agents share their host session.
- **Generations with real usage:** prompt, completion, cache, and reasoning tokens go out as exclusive `usageDetails` buckets.
- **Tool spans:** a finished or suspended tool call hangs under the `step` span that owns its generation.
- **No runtime coupling:** export failures are logged and never thrown into the LLM call.

## Installation {#installation}

**Requirements:** PHP 8.3+, `ext-json`, `ext-mbstring`. `ext-swoole` is optional and only enables the export queue.

```bash
composer require switon/langfuse
```

`ClientInterface` auto-maps to `Switon\Langfuse\Client`.

## Configuration {#configuration}

Disabled until `baseUrl` is set. `ClientInterface` auto-maps to `Client`, so the entry needs settings only:

```yaml
# switon.yml
Switon\Langfuse\ClientInterface:
  baseUrl: https://cloud.langfuse.com
  publicKey: pk-lf-...
  secretKey: sk-lf-...
  timeout: 3
  serviceName: my-app
```

| Property | Default | Meaning |
|----------|---------|---------|
| `baseUrl` | `''` | Host. Empty disables listener registration and every export. |
| `publicKey` | `''` | Basic-auth user. Missing credentials log once and skip the export. |
| `secretKey` | `''` | Basic-auth password. |
| `timeout` | `3` | Export request timeout in seconds. |
| `serviceName` | `switon` | OTLP `service.name` resource attribute. |
| `queueSize` | `256` | Batches held for the consumer coroutine; `0` sends inline. |

`baseUrl` also accepts a host without a scheme (`cloud.langfuse.com`); the exporter adds `https://`.

## Core Concepts {#core-concepts}

### Trace shape {#shape}

A `Thread` holds `Turn`s, a `Turn` holds `Completion`s ordered by `$step`. Each step becomes an `agent` span; the model call and every tool it requested are its children:

```text
release-notes.answer        (trace: thread + profile)
└── turn                    (root span)
    ├── step-1  agent
    │   ├── call-tool           generation
    │   └── web_search          tool
    └── step-2  agent
        └── answer-with-tool-result  generation
```

A generation and its tools are **siblings** under the `step` span, not nested: a tool under its generation would claim the generation owned the tool's wall-clock time.

### What gets exported {#what-gets-exported}

| Event | Observation | Trace id |
|-------|-------------|----------|
| `CompletionResponded` | `generation` — input, output, model, `modelParameters`, usage, timings | open turn id, else the completion id |
| `CompletionFailed` | `generation` `failed-llm-call`, `ERROR` level, exception class in metadata | same |
| `ToolFinished` | `tool` span — arguments, result, recall path; a dispatched sub-agent (`AsToolResult`) is typed `agent` | turn that requested the call |
| `ToolFinished` (failed) | `tool` span at `ERROR` level, exception as `statusMessage` | same |
| `TurnSuspended` (tool interrupt) | `tool` span on `{callId}:suspend` | the suspended turn |
| `TurnSuspended` (retry) | `event` span | the suspended turn |
| `DeliverySettled` | re-sends the `turn` root with final `status` and `closed_at` | the settled turn |

Every export also re-sends one `turn` root under a stable span id. OTLP upserts on span id, so the root widens to the whole turn instead of duplicating, and it always repeats the same input/output: a resend without them erases both on the observation and on the trace.

The export belongs to the **turn that produced it**, not the newest turn. A tool finishing after the next turn opened still writes its own turn's trace, dialogue, and metadata.

### Where each field lands {#fields}

| Field | Destination |
|-------|-------------|
| `llm_calls`, `tool_calls`, `llm_wall`, `status`, `opened_at`, `closed_at`, `effort` | trace metadata |
| `cache_hit`, `cache_ideal`, `finish_reason` | generation metadata — Langfuse infers cost but not hit ratio |
| `environment`, `release` | `AppInterface::env()` / `version()` |
| `langfuse.trace.name` | catalog profile id, else the thread id |
| `langfuse.session.id` | host thread id; a sub-agent `{host}.{leaf}` shares its host |

Observation names are stable action names (`call-tool`, `answer-with-tool-result`, `answer`, `tool · caption`), never reply text: Langfuse matches evaluators, dashboards, and saved filters on the name. The reply stays in `output`.

### Application metadata {#app-metadata}

`Message::$attributes`, `Completion::$attributes`, `ToolCall::$attributes`, `ToolResult::$attributes` and `Turn::$attributes` are exported flat and filterable. A key colliding with one this component writes is kept as `attr.<key>` rather than dropped.

`Turn::$attributes` is the exception: two keys have dedicated homes, the rest become trace metadata.

| Turn attribute | Exported as |
|----------------|-------------|
| `user_id` | `langfuse.user.id` |
| `tags` | `langfuse.trace.tags` (string or list) |
| any other key | `langfuse.trace.metadata.<key>` |

## Under Swoole, exports are queued {#queue}

With coroutine hooks, a curl export yields instead of blocking, so overlapping exports for one trace reach Langfuse out of order and it drops observations. `ingest()` therefore enqueues and returns; one consumer coroutine sends in FIFO order. Blocking runtimes skip the queue and export inline.

Drain before process exit — inject the interface wherever your shutdown hook lives:

```php
#[Autowired] protected ClientInterface $langfuse;

$this->langfuse->shutdown(5.0);
```

- `shutdown()` stops intake, waits for every accepted batch including the one in flight, then lets the worker exit.
- The wait is bounded by `$timeout` and counts accepted batches, not queue length: the worker pops a batch before its request runs.
- `Termination::terminate()` only calls `close()`, which stops intake without waiting. Call `shutdown()` when the drain must complete.
- The worker exits after roughly a second idle and the next `ingest()` starts a new one, so a process that never calls `shutdown()` still lets `Swoole\Coroutine\run()` return.

## Cost needs a model definition {#cost}

Langfuse prices a generation by matching its `model` against its table. Popular OpenAI, Anthropic, and Google models are covered; anything else shows `$0.000000` until you add it under **Project Settings → Models** using the wire name (`MiniMax-M2.7`).

The package does not send cost itself: Langfuse ignores `langfuse.observation.cost_details` on non-SDK OTel spans.

## Keep in Mind {#keep-in-mind}

- The exporter targets OTLP (`/api/public/otel/v1/traces`), served by both v3 and v4. The legacy `/api/public/ingestion` path is not used.
- Read data back through Observations API v2 and Metrics API v2; pre-v4 read endpoints return `404` on v4.
- If nothing appears in Langfuse, check `baseUrl` first — an empty host skips listener registration entirely.

## Next {#next}

- **To debug behavior:** [FAQ](faq.md#langfuse-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
- **Langfuse side:** [OTLP integration](https://langfuse.com/integrations/native/opentelemetry)
