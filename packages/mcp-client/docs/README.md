# MCP Client Package {#mcp-client-package}

`switon/mcp-client` calls tools on other MCP servers. Choose it when an agent should discover those tools and invoke them, without merging them into the local tool catalog. The package standardizes the server file, the connection, and four agent tools. Which servers exist, and what their tools do, stays outside this package.

**Key features:**

- **Server file:** `~/.switon/mcp.yml`. One key is one server, either `command` or `url`.
- **Lazy connection:** the first `tools` or `call` for that id opens it. `mcp_servers` does not connect.
- **Tool pages:** `tools/list` follows `nextCursor` until the cursor repeats.
- **Agreed protocol:** the client offers `2025-03-26` and keeps whatever version string the server returns.
- **HTTP body:** a buffered `application/json` body or a buffered `text/event-stream` body. A `202` with no JSON-RPC message fails.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/mcp-client
```

The service provider reads the server file at boot. The four tools register themselves.

## Quick Start {#quick-start}

```yaml
docs:
  command: bash
  args: [bin/mcp-serve]
```

Save that as `~/.switon/mcp.yml`. `docs` is the server id.

From an agent, `mcp_servers` lists ids, `mcp_tools` lists that server's tools, `mcp_schema` reads one argument schema, and `mcp_call` runs one tool. Pass `server` and `tool` to `mcp_call`. Put the remote arguments in `arguments`.

An HTTP server uses `url` instead of `command`:

```yaml
github:
  url: https://example.com/mcp
  headers:
    Authorization: Bearer token
```

Set `enabled: false` to skip a key without deleting it.

### Config {#config}

`Switon\McpClient\ClientDiscovery` reads the file. Override the path or the client name from `switon.yml`:

```yaml
Switon\McpClient\ClientDiscovery:
  file: ~/.switon/mcp.yml
  mcpClientInfo: switon-agent
```

`file` defaults to `~/.switon/mcp.yml`. A missing file leaves the catalog empty. `mcpClientInfo` is the `initialize` client name and defaults to `switon-agent`. Boot calls `discover()` once. Call `discover()` again to reread the file. An unchanged entry keeps its client.

## Core Concepts {#core-concepts}

### Server id {#server-id}

The YAML key is the id passed as `server` to `mcp_tools`, `mcp_schema`, and `mcp_call`. It must not contain `_`. `enabled: false` omits that key from the catalog.

A `command` entry spawns a process. `args`, `env`, `cwd`, and `readTimeout` are the stdio transport settings. `readTimeout` is seconds for one read, fractions allowed, and defaults to `30`.

A `url` entry sends HTTP. `headers` are extra request headers, such as `Authorization`.

### Catalog and call {#catalog-and-call}

`Switon\McpClient\ClientInterface::listTools()` keeps the tool map for that connection. `mcp_tools` with `reload: true` fetches it again. Flat argument names come back as `params`. Nested arguments come back as the sentence `Params structure is complex. Read with mcp_schema.`

`mcp_call` returns text. A long result is spilled under the thread data directory. Image blocks are stored beside it and returned as content parts. A remote `isError` stays an error result.

### Connection {#connection}

The first request opens the transport and sends `initialize`, then `notifications/initialized`. The client offers `2025-03-26` and keeps the version string the server returns. A missing version closes the connection. After that, HTTP requests send `MCP-Protocol-Version` and `Mcp-Session-Id` when the server issued a session.

An idle connection sends `ping` before the next request. The idle window defaults to `30` seconds on `Switon\McpClient\Client`. A dead idle `ping` reconnects before that request. An idle `ping` the server rejects is skipped from then on. A request that already failed is not sent again. `tools/call` can be aborted while the bytes are in flight.

Stdio is newline-delimited JSON-RPC on the child process. HTTP is one POST per message. The POST accepts `application/json` and `text/event-stream`, and reads whichever buffered body the server sends. This client does not open a GET event stream, so `202 Accepted` without a JSON-RPC message is a failure.

## Next {#next}

- **Common questions:** [FAQ](faq.md#mcp-client-faq)
- **Trade-offs and boundaries:** [Design Rationale](design.md#design-rationale)
