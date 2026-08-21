# MCP Client FAQ {#mcp-client-faq}

Short answers for the most common `mcp-client` questions.

> **Prerequisites:** [MCP Client Package](README.md)

## Fit {#fit}

### Is this the MCP server package? {#server-or-client}

No. `switon/mcp` publishes this app's tools. `switon/mcp-client` calls tools on other servers.

### Does the client load resources or prompts? {#resources-prompts}

No. The client lists and calls tools. Resources, prompts, sampling, and roots stay on the server.

### Why can't a server id contain `_`? {#server-id}

`_` is reserved so a server id stays a single token in tool arguments. Rename the YAML key.

## HTTP {#http}

### Why does a `202` response fail? {#accepted}

The transport reads the POST body only. It does not hold a GET event stream, so a `202` with no JSON-RPC message has nothing to return.

### Which protocol versions work? {#protocol-versions}

The client offers `2025-03-26`. It keeps any version string in the `initialize` result and sends that value as `MCP-Protocol-Version`. The connection closes only when that field is missing or blank. A server that no longer answers `initialize` still cannot be used.

## Catalog {#catalog}

### When is `mcp.yml` read again? {#reread}

Boot calls `discover()` once and does not connect. Call `discover()` again to reread the file. An unchanged entry keeps its client. A changed or removed entry drops the previous client. There is no timer.
