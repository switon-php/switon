# Switon MCP Client Package

[![MCP Client CI](https://img.shields.io/github/actions/workflow/status/switon-php/mcp-client/ci.yml?branch=main&label=MCP%20Client%20CI)](https://github.com/switon-php/mcp-client/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's Model Context Protocol client: a server catalog, stdio and streamable HTTP transports, and four agent tools for listing and calling remote tools.

## Highlights

- **One file, many servers:** `~/.switon/mcp.yml` names each server (stdio `command`/`args`/`env` or HTTP `url`/`headers`).
- **Connect on first use:** `Switon\McpClient\ClientDiscoveryInterface` registers a lazy client per server. A tool call opens it.
- **Two transports:** newline-delimited JSON-RPC over stdio, or one POST per message over streamable HTTP.
- **Four agent tools:** `mcp_servers`, `mcp_tools`, `mcp_schema`, and `mcp_call`.
- **Catalog CLI:** `mcp-client:list` / `get` / `add` / `remove` / `enable` / `disable`, plus `import`, `tools`, `schema`, `ping`, and `call`.

## Installation

```bash
composer require switon/mcp-client
```

## Quick Start

```yaml
docs:
  command: bash
  args: [bin/mcp-serve]
github:
  url: https://example.com/mcp
  headers:
    Authorization: Bearer token
```

Save that as `~/.switon/mcp.yml`, or build it with the CLI:

```bash
mcp-client:add docs --url=https://example.com/mcp --header=Authorization=Bearer token
mcp-client:add app --env=API_KEY=secret -- npx -y some-mcp
mcp-client:import --force
```

An installed app then exposes `mcp_servers`, `mcp_tools`, `mcp_schema`, and `mcp_call`.

Docs: https://github.com/switon-php/mcp-client/blob/main/docs/README.md

## License

MIT.
