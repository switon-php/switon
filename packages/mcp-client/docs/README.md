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

From the CLI (`mcp-client`):

```bash
mcp-client:list
mcp-client:get docs
mcp-client:add docs --url=https://example.com/mcp
mcp-client:add docs --url=https://example.com/mcp --header=Authorization=Bearer token
mcp-client:add app -- npx -y some-mcp
mcp-client:add app --env=API_KEY=secret --env=DEBUG=1 -- npx -y some-mcp
mcp-client:add app --force -- npx -y some-mcp
mcp-client:enable docs
mcp-client:disable docs
mcp-client:remove docs
mcp-client:ping docs
mcp-client:tools docs
mcp-client:schema docs search
mcp-client:call docs search '{"q":"readme"}'
mcp-client:call docs search @args.json
echo '{"q":"readme"}' | mcp-client:call docs search -
mcp-client:call docs search '{"q":"readme"}' --raw
mcp-client:import
mcp-client:import --dry
mcp-client:import --force
mcp-client:import --force --dry
```

`list` briefs strip URL query strings. `get` prints one id's entry from `~/.switon/mcp.yml` as YAML (does not connect). `add` / `remove` / `enable` / `disable` rewrite the file only (the process exits; the next boot rediscovers). `add` takes `--url=` or `-- <command> [args...]` (not both); `--force` replaces an existing id; repeatable `--env=KEY=VALUE` attaches to stdio entries; repeatable `--header=Name=value` attaches to HTTP entries (value may contain `=`). `call` binds arguments against the remote schema (same binder as `mcp_call`) before `tools/call`; default output is text blocks (`--raw` for wire JSON). Arguments may be a JSON object, `@file`, or `-` / `@-` (stdin).

`import` walks every path in `extra.switon.mcp.importers` that exists on disk, merges entries (later paths overwrite same ids), writes `~/.switon/mcp.yml`, then prints the source paths and an `id` / `brief` table (console adds row numbers). `--dry` prints that preview without writing (and does not refuse when `mcp.yml` already exists). Without `--force` (and without `--dry`), an existing `mcp.yml` refuses. With `--force`, the existing `mcp.yml` is appended via `SwitonYamlImporter` last so its ids win. Only home-absolute paths (`~/…`) are listed: most clients share `mcpServers` JSON (Cursor, Claude Code / Desktop, Copilot CLI, Qoder, CodeBuddy, Trae, Gemini, Windsurf/Devin, LM Studio, Cline, Roo, Amazon Q, Kiro, Amp, Augment, Lingma, CodeGeeX, Workbuddy, …); VS Code User `mcp.json` uses `servers` (same importer, prefers `mcpServers` when both exist); Codex uses `[mcp_servers.*]` TOML. Project-relative configs, IntelliJ Copilot JSONC `servers`, and Zed `context_servers` are not in that map — call a specific importer class for a one-off path. Unknown shapes and bad entries are skipped. It does not run on boot — the app decides when to call it.

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

`file` defaults to `~/.switon/mcp.yml`. A missing file leaves the catalog empty. `mcpClientInfo` on `Clients` is the `initialize` client name and defaults to `switon-agent`. Boot calls `discover()` once (reads the file and `Clients::add` each entry). Call `discover()` again to reread. An unchanged entry keeps its client.

## Core Concepts {#core-concepts}

### Server id {#server-id}

The YAML key is the id passed as `server` to `mcp_tools`, `mcp_schema`, and `mcp_call`. `enabled: false` omits that key from the catalog.

A `command` entry spawns a process. `args`, `env`, `cwd`, and `readTimeout` are the stdio transport settings. `readTimeout` is seconds for one read, fractions allowed, and defaults to `30`.

A `url` entry sends HTTP. `headers` are extra request headers, such as `Authorization`.

### Catalog and call {#catalog-and-call}

`Switon\McpClient\ClientInterface::listTools()` keeps the tool map for that connection. `mcp_tools` with `reload: true` fetches it again. Flat argument names come back as `params`. Nested arguments come back as the sentence `Params structure is complex. Read with mcp_schema.`

`mcp_call` binds nested `arguments` against the remote tool schema (via `Switon\Tooling\ArgumentBinderInterface`, including string↔integer coerce) before `tools/call`. It returns text. A long result is spilled under the thread data directory. Image blocks are stored beside it and returned as content parts. A remote `isError` stays an error result.

### Connection {#connection}

The first request opens the transport and sends `initialize`, then `notifications/initialized`. The client offers `2025-03-26` and keeps the version string the server returns. A missing version closes the connection. After that, HTTP requests send `MCP-Protocol-Version` and `Mcp-Session-Id` when the server issued a session.

Each discovered client is registered with `Switon\Sync\TerminationInterface` as a `CloseableInterface`. CLI `Server::handle` calls `terminate()` in the same coroutine before the process exits, so HTTP session DELETE and stdio teardown do not run from `__destruct` outside a coroutine. `HttpTransport::close` also skips DELETE when `Coroutine::getCid() < 0` as a last resort.

An idle connection sends `ping` before the next request. The idle window defaults to `30` seconds on `Switon\McpClient\Client`. A dead idle `ping` reconnects before that request. An idle `ping` the server rejects is skipped from then on. A request that already failed is not sent again. `tools/call` can be aborted while the bytes are in flight.

Stdio is newline-delimited JSON-RPC on the child process. HTTP is one POST per message. The POST accepts `application/json` and `text/event-stream`, and reads whichever buffered body the server sends. This client does not open a GET event stream, so `202 Accepted` without a JSON-RPC message is a failure.

## Next {#next}

- **Common questions:** [FAQ](faq.md#mcp-client-faq)
- **Trade-offs and boundaries:** [Design Rationale](design.md#design-rationale)
