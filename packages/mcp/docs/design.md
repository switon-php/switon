# Design Rationale {#design-rationale}

`switon/mcp` keeps the tool catalog small and the same on stdio and HTTP.

## See also {#see-also}

- [MCP Package](README.md) — usage, configuration, and the public contract
- [FAQ](faq.md#mcp-faq) — common questions and troubleshooting

---

## Core Problem {#core-problem}

Tool servers drift as soon as each app invents its own wire format.

- One tool casts JSON booleans into `""` and `0`.
- Another tool reports a client mistake and a domain failure with the same JSON-RPC error.
- A third tool binds the catalog to one transport, so HTTP and stdio diverge.

The framework needs one catalog boundary that stays obvious in application code.

## Primary Goal {#primary-goal}

`switon/mcp` standardizes the catalog and the envelope first, then leaves tool behavior to the app.

- **Benefit:** a tool is `schema()` plus `call()`, on both transports.
- **Benefit:** clients can read a tool failure and correct the next call.
- **Cost:** resources, prompts, and a full JSON Schema engine stay outside.

## Core Choice {#core-choice}

Each `tools/call` is one catalog lookup and one tool invocation.

- **The catalog** is a name map. Discovery only fills names that are not already registered.
- **Binding** fills defaults, checks required fields, and coerces string and integer. Extra fields stay.
- **Tool failures** become `ToolResult` with `isError`. Envelope mistakes stay JSON-RPC and never reach the tool.
- **`ProtocolHandler::call()`** is the override point when an app wants binder failures to leave as `InvalidParamsException`.

That keeps application tools free of transport details.

## Trade-Off {#trade-off}

The binder is not a schema validator, and tool failures are not protocol errors.

- **Benefit:** `"1"` and `1` coerce, while `true` and `false` do not become numbers or empty strings.
- **Benefit:** an unknown tool or a thrown exception is visible to the model as text.
- **Cost:** `additionalProperties: false` is only a published hint. Nested objects are not bound.

## Deliberate Simplicity {#deliberate-simplicity}

The package ships the catalog, not a product server.

- **HTTP routing and authorization** stay in the app controller.
- **Session** exposes the id and the raw access key. It does not authenticate.
- **Resources and prompts** answer with empty lists.
- **Listeners** (`ToolCalling`, `ToolCalled`) adjust one call. They do not replace the catalog.
