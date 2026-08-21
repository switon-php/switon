# Design Rationale {#design-rationale}

`switon/mcp-client` is a tool caller, not a second tool catalog. Remote tools stay behind `mcp_tools`, `mcp_schema`, and `mcp_call` so the local agent catalog does not grow with every server.

## See also {#see-also}

- [MCP Client Package](README.md) — server file, tools, and connection
- [FAQ](faq.md#mcp-client-faq) — fit, HTTP, and catalog refresh

## Why remote tools stay behind four tools {#four-tools}

Copying every remote tool into the local catalog would publish names the app does not own and would refresh that catalog on every server change.

The four tools keep the boundary visible: `mcp_servers` is configuration, `mcp_tools` and `mcp_schema` are the remote catalog, and `mcp_call` is the invocation. The remote name travels as an argument.

## Why the server's protocol string is kept {#protocol-string}

The tools calls this client makes did not change when the protocol date did. A whitelist drops a server that finished `initialize` and named a date the list had not caught up with.

The offer stays `2025-03-26`, which this package's own server echoes. The returned string is stored and sent back on HTTP. Only a missing or blank version closes the connection.

## Why the HTTP transport does not listen {#http-post}

Streamable HTTP allows a server to answer a POST with JSON or with a short event stream, and it allows a later answer on a GET stream.

This client handles the first case: one POST, then the buffered JSON body or the buffered event-stream body. It does not keep a GET stream open. A `202` without a JSON-RPC message fails instead of waiting for a channel this transport does not own.

## What stays outside {#outside}

Server processes, authorization headers, and the meaning of each remote tool stay in `mcp.yml` and on that server. This package does not turn those tools into local PHP classes.
