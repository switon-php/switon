# MCP FAQ {#mcp-faq}

Short answers for tools, arguments, protocol errors, and the two transports.

> **Prerequisites:** [MCP Package](README.md)

## Getting Started {#getting-started}

### How do I add a tool? {#add-tool}

Extend `Switon\Mcp\AbstractTool`, implement `schema()` and `call()`, and place the class in `app/Mcp/Tool/` with a `Tool` suffix. See [Quick Start](README.md#quick-start).

### What name do clients see? {#tool-name}

Discovery publishes the class short name without a trailing `Tool`, in snake_case. `OrderStatusTool` becomes `order_status`. An entry under `Switon\Mcp\ToolsInterface` `tools` uses the config key instead, and that key wins when both exist.

### How do I serve the catalog? {#serve}

Stdio: `bash bin/mcp-serve` (it runs `mcp:serve`). Streamable HTTP: your controller calls `Switon\Mcp\HttpServerInterface::handle()`. See [Transport](README.md#transport).

## Tools {#tools}

### Why is a class on disk missing from `tools/list`? {#tool-missing}

`schema()` returned `null`, or the name was already in the catalog so discovery skipped it. `has()` can still be true for a hidden name. See [Catalog](README.md#catalog).

### How do I turn discovery off? {#disable-discovery}

Clear the default glob. An empty `files` map is merged onto that default, so set the default key to null:

```yaml
Switon\Mcp\ToolDiscoveryInterface:
  files:
    '@app/Mcp/Tool/*Tool.php': null
```

A missing `app/Mcp/Tool/` directory is already a no-op.

### Can one class serve several names? {#several-names}

`schema()` and `call()` both receive the published name. Register the same class under more than one `tools` key when the names differ.

## Arguments {#arguments}

### Why did `false` fail on a string field? {#bool-rejected}

String and integer fields reject booleans. `"1"` still becomes `1`, and `1` still becomes `"1"`. See [Arguments](README.md#arguments).

### Are extra fields an error? {#extra-fields}

No. The binder keeps them. Pass `additionalProperties: false` to `Switon\Mcp\ToolSchema` only when the published schema should say extras are disallowed. The binder still does not reject them.

### Does the binder validate nested objects? {#nested}

No. It fills defaults, checks `required`, and coerces top-level `string` and `integer` properties. Array and object values are unchanged.

## Protocol {#protocol}

### When is a failure JSON-RPC, and when is it `isError`? {#error-shape}

A bad envelope is JSON-RPC: missing method, a non-object `params`, a missing or non-string tool name, or non-object `arguments` (`-32602` for the param cases). After a tool is selected, unknown names, binder failures, and throws stay in the result as `isError: true` with text `{class, content}`. See [Call result](README.md#call-result).

### How do I surface binder failures as `-32602`? {#promote-invalid-params}

Subclass `Switon\Mcp\ProtocolHandler`, override `call()`, and rethrow `Switon\Mcp\Exception\InvalidParamsException`. The default `call()` catches it and returns a tool result.

### What do `ToolCalling` and `ToolCalled` change? {#events}

`Switon\Mcp\Event\ToolCalling` can replace `arguments` before binding. `Switon\Mcp\Event\ToolCalled` can replace `result` before the response is written. Neither runs when the tool name is unknown.

## Transports {#transports}

### What must the HTTP request include? {#http-request}

`Accept` must include `application/json` and `text/event-stream`. A present `Origin` must be this host, or one of `allowedOrigins`. The body must be a JSON object. See [Configuration](README.md#configuration).

### Where does the session id come from? {#session-id}

A successful `initialize` response sets `Mcp-Session-Id`. Later requests send that header. `Switon\Mcp\SessionInterface::getId()` reads the request, not the response just written.

### How does a tool see the caller credential? {#access-key}

Inject `Switon\Mcp\SessionInterface`. `getAccessKey()` returns the `Authorization: Bearer` token, otherwise the `access_key` query value. This is the raw credential. Checking it is the app's job.

## Troubleshooting {#troubleshooting}

### `initialize` worked, but `tools/call` says the tool was not found {#not-found}

The name is not in `all()`. Check the published name, an explicit `tools` key, and whether `schema()` returns `null`.

### The client shows a tool error instead of a protocol error {#is-error}

That is the default for binder failures and tool throws. Promote `InvalidParamsException` only when the client should treat it as a bad request. See [Call result](README.md#call-result).

### HTTP returns 406 {#accept}

The `Accept` header omitted `application/json` or `text/event-stream`.
