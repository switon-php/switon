# app-docfs

Switon sample for **docfs**: Git-native technical docs. Open source; not monetized.

**LLM Markdown maintenance = MCP tools** (`list_books` / `list_files` / `read` / `write` / `patch` / …) — no general HTTP FS API.  
**Binary assets = HTTP** `POST|GET|DELETE /books/{book}/assets…` with the **same JWT** as MCP (Bearer / `access_key`).  
**Direct Git** still works (same layout).  
Optional later: HTTP to **read** Markdown in a browser.

## Ideal write paths

1. LLM / agent **edits the book Git repo directly**
2. LLM **calls MCP tools** for Markdown (same files)
3. Clients **upload blobs** via asset HTTP (or Git)

See [`docs/docfs.md`](docs/docfs.md), [`docs/mcp.md`](docs/mcp.md), [`docs/maintenance-modes.md`](docs/maintenance-modes.md).

## Model

| Concept | Identity | Storage |
|---------|----------|---------|
| Book | `slug` | one Git repository (docfs) |
| Docs | flat `.md` name | `{book}` + `{file.md}` (MCP); on disk under book root |
| Required | `index.md`, `AGENT.md` | seeded by `mkbook` |
| Assets | `{uuid}.{ext}` under `assets/` | HTTP `/books/{book}/assets` (or Git); not MCP bytes |
| Concurrency | per-file `sha` | optimistic lock on MCP write/patch / asset DELETE |

```text
Human ↔ LLM ┬─ edit Git directly ─┐
            ├─ MCP tools (md) ────┼─→ same docfs book repo → optional read UI
            └─ HTTP assets (blob)─┘
```

## Setup

```bash
cd app-docfs
cp .env.example .env
composer install
```

### MCP (stdio)

```bash
bash bin/mcp-serve
```

Cursor / Claude Desktop example:

```json
{
  "mcpServers": {
    "docfs": {
      "command": "bash",
      "args": ["/absolute/path/to/app-docfs/bin/mcp-serve"]
    }
  }
}
```

### MCP (HTTP streamable)

```bash
bash bin/serve   # :9520
# POST http://127.0.0.1:9520/mcp
```

## MCP tools (v0)

| Tool | Role |
|------|------|
| `list_books` | bookshelf (ACL-visible books) |
| `list_files` | all file shas in one book |
| `read` | `book` + `file` → `lines[]` (+ `sha`; optional `rev`) |
| `log` | git history for book or file |
| `diff` | unified diff between revs |
| `grep` | search flat `.md` in one book |
| `mv_file` | rename flat `.md`; empty `to` = delete (needs `sha` + `git_message`) |
| `write` | whole `.md` + `git_message` (+ expected `sha` if exists) |
| `patch` | ordered `ops[]` (`replace_lines` / `replace_span`) + `sha` + `git_message` |
| `mkbook` | create volume (`book` + `index.md` + `AGENT.md`) |

### Asset HTTP (same JWT)

| Method | Path | Role |
|--------|------|------|
| `POST` | `/books/{book}/assets` | multipart `file` + `git_message` → `assets/{uuid}.{ext}` |
| `GET` | `/books/{book}/assets/{uuid.ext}` | download bytes |
| `DELETE` | `/books/{book}/assets/{uuid.ext}` | `sha` + `git_message` |

Full contract: [`docs/mcp.md`](docs/mcp.md).

## Config

| Variable | Purpose |
|----------|---------|
| `BOOKS_ROOT` | Absolute path for `@books` (default `@runtime/books`) |
| `DOCFS_JWT_KEY` | HMAC key for JWT (required; no token → tools fail) |
