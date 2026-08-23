---
description: MCP gateway — list servers, list tools, read one schema, call by server + tool name
tools:
  - mcp_servers
  - mcp_tools
  - mcp_schema
  - mcp_call
---
## MCP

`mcp_servers` lists configured servers. `mcp_tools` lists one server's tool names, descriptions, and flat `params` (`[]` means none). `Params structure is complex. Read with mcp_schema.` means an argument is nested. It fetches that server once; pass `reload: true` to fetch it again. `mcp_schema` returns one tool's argument schema. Call with `mcp_call` (`server` + `tool` + `arguments`).
