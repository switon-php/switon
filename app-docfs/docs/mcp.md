# docfs MCP tools (target v0)

Audience: **LLM agents via MCP**.  
Status: **design contract** — prototype may lag.

Related: [`docfs.md`](docfs.md) · [`file-layout.md`](file-layout.md) · [`maintenance-modes.md`](maintenance-modes.md)

## Decision

**No general-purpose HTTP FS API** (`/api/ls`, `/api/write`, …).  
Maintenance surface for Markdown is **MCP tools** (Switon MCP).

**Exception — binary assets:** HTTP blob API (`POST|GET|DELETE /books/{book}/assets…`) with the **same JWT** as MCP (`Authorization: Bearer` / `access_key`). MCP does **not** carry asset bytes (token cost).

Optional later: HTTP for **human Markdown read** (browse/render) — not for LLM Markdown writes.

Direct Git remains first-class (no server required).

## Mental model

**Book** = volume / git repo. Tools take explicit **`book`** + book-relative **`file`** (flat `.md`):

```text
list_books                  → bookshelf (entries = books)
list_files book=demo        → all file shas for demo
read book=demo file=index.md
read book=demo file=AGENT.md
write book=demo file=overview.md …
```

| Tool | Meaning |
|------|---------|
| `list_books` | list ACL-visible books |
| `list_files` | list all files + shas in one book (docs + assets) |
| `read` | read `.md` (+ optional `rev` for history) |
| `log` | git history for book or file |
| `diff` | unified diff between two revs |
| `grep` | search flat `.md` in one book (+ optional `file`) |
| `mv_file` | rename flat `.md` (+ rewrite in-book `old.md` refs); omit empty `to` = delete (not `index.md` / `AGENT.md`) |
| `write` | whole-file put (`.md` only) |
| `patch` | ordered `ops[]` (`replace_lines` / `replace_span`) on one `.md` + `sha` + `git_message` |
| `mkbook` | create book with `index.md` + `AGENT.md` |

Binary assets: HTTP `POST /books/{book}/assets` (multipart `file` + `git_message`) — not an MCP tool.

First call: **`list_books`**. Then **`read` book=`demo` file=`index.md`** and **`AGENT.md`**. Sync: **`list_files` book=`demo`** → sha map.

## Principles

1. Git is SSOT; tool writes = same bytes as editing the repo.
2. **write / patch are mechanical** — no parse→edit→dump.
3. **File-scoped concurrency:** existing file needs expected `sha`; mismatch → current `sha` in error (retry with it; re-`read` only if body needed).
4. Book `commit` optional metadata; not the write gate.
5. **Mutating tools** (`write` / `patch` / `mv_file`) take required **`git_message`** (git subject; why/what, no filename; max 100). `mkbook` uses a fixed first-commit subject. Asset HTTP put/delete also require `git_message`.
6. Auth: JWT payload `books` map (`PERM_READ` / `PERM_WRITE`) — MCP and asset HTTP share the same Bearer / `access_key`.

## Tool results

File entries (slim):

| Field | Notes |
|-------|--------|
| `file` | book-relative path (`index.md` or `assets/{uuid}.ext`) |
| `size` | bytes |
| `mtime` | unix |
| `sha` | content/blob SHA-1, **7-char abbrev** (git-style); inputs may be 7–40 hex prefix |
| `commit` | optional book HEAD (**7-char abbrev**) |
| `lines` | `read` always returns `lines[]` (line *N* = `lines[N-1]`). |

Bookshelf `list_books` entries: `book` only.  
No `name` / `type` / `ctime` / absolute `path`.

### Stale file

Return `sha_mismatch` with current `file` / `sha` → agent uses that `sha` to retry (re-`read` only if it needs body).

---

## Tools

### `list_books`

No args. Returns ACL-visible books.

### `list_files`

| Arg | Type | Notes |
|-----|------|--------|
| `book` | string | book slug (required) |

### `read`

| Arg | Type | Notes |
|-----|------|--------|
| `book` | string | book slug |
| `file` | string | flat `.md` (e.g. `index.md`) |
| `rev` | string\|omit | git commit; omit = working tree |

Always returns `lines[]` (line *N* is `lines[N-1]`).

### `log`

| Arg | Type | Notes |
|-----|------|--------|
| `book` | string | book slug |
| `file` | string\|omit | flat `.md`; omit = whole book |
| `limit` | int | default 20, max 100 |

Returns `commits[]` with `commit` / `time` / `message` (`commit` = **7-char abbrev**). File scope uses `git log --follow`.

Messed-up doc recovery: `log` → `read` with `rev` → `write` current with HEAD `sha`.

### `diff`

| Arg | Type | Notes |
|-----|------|--------|
| `book` | string | book slug |
| `file` | string\|omit | flat `.md`; omit = whole book |
| `rev` | string | base (older) rev |
| `rev2` | string\|omit | newer rev; default `HEAD` |

Returns unified `diff` text plus resolved `commit` / `commit2` as **7-char abbrev** (capped ~200KB, `truncated` if more). Inputs `rev`/`rev2` still accept full or abbrev prefixes.

### `grep`

| Arg | Type | Notes |
|-----|------|--------|
| `book` | string | book slug |
| `pattern` | string | PCRE pattern (no delimiters) |
| `file` | string\|omit | root `.md` name or glob (`concepts-*.md`); omit = all root `.md` |

Returns `matches[]` of `{file, sha, hits[{line, text}]}` (capped at 200 hits; `truncated` if more).

### `mv_file`

| Arg | Type | Notes |
|-----|------|--------|
| `book` | string | book slug |
| `file` | string | source flat `.md` (not `index.md` / `AGENT.md`) |
| `to` | string\|omit | destination flat `.md`; **omit or empty = delete** |
| `sha` | string | **required** |
| `git_message` | string | **required** — git commit subject (max 100) |

Destination must not exist. Binary blobs are not renamed here (use asset HTTP / Git).  
On **rename**, also rewrites whole-token `file` → `to` inside every flat `*.md` in the same book (not cross-book; `not-foo.md` untouched when renaming `foo.md`). Response includes `links_updated: true`, a `note` telling agents not to re-patch links, and `rewritten[{file,sha}]`. Delete does not rewrite refs.

### `write`

| Arg | Type | Notes |
|-----|------|--------|
| `book` | string | book slug |
| `file` | string | flat `.md` |
| `content` | string | full file |
| `git_message` | string | **required** — git commit subject (max 100) |
| `sha` | string\|null | required if file exists; ignored if creating |

### `patch`

| Arg | Type | Notes |
|-----|------|--------|
| `book` | string | book slug |
| `file` | string | flat `.md` |
| `sha` | string | expected current content sha |
| `git_message` | string | **required** — git commit subject (max 100) |
| `ops` | object[] | **required**, non-empty; applied in order on this file |

Each `ops[]` item:

| `op` | Fields |
|-----|--------|
| `replace_span` | `old`, `new`, optional `count` (default 1; `0` = all) |
| `replace_lines` | `start_line`, `end_line` (1-based inclusive), `content` |

Line numbers for `replace_lines` are relative to the buffer **after** earlier ops (stack line edits bottom-up when unsure). One commit for the whole `ops` list.

### Asset HTTP (not MCP)

Same JWT as MCP (`Authorization: Bearer` / `access_key`). Blobs stay out of tool args.

| Method | Path | Notes |
|--------|------|--------|
| `POST` | `/books/{book}/assets` | multipart `file` + `git_message`; optional `name` (ext source). → `assets/{uuid}.{ext}` |
| `GET` | `/books/{book}/assets/{uuid.ext}` | raw bytes |
| `DELETE` | `/books/{book}/assets/{uuid.ext}` | query/body `sha` + `git_message` |

`POST` response: `file` / `original` / `sha` / size metadata — **no content echo**.  
Markdown should link the returned file (e.g. `assets/{uuid}.png`). Deleting does **not** rewrite Markdown.

### `mkbook`

| Arg | Type | Notes |
|-----|------|--------|
| `book` | string | book slug (`[A-Za-z\d.-]+`) |

Creates git repo + empty `index.md` and `AGENT.md`.  
First commit subject is fixed: `Initialize book with index.md and AGENT.md`.  
Response: `{ book, entries[] }` (file shas for follow-up writes).

---

## Wiring (Switon)

- One class per tool under `app/Mcp/Tool/*Tool.php`, extending `DocfsTool` → `Switon\Mcp\AbstractTool`; cache schema with `$this->schema ??= new ToolSchema(...)`.
- Shared: `App\Docfs\Git` (CLI + `hashObject`); I/O via `FilesystemInterface` with `@books/...`.
- Auth: `CredentialInterface::payload()` — session Bearer / `access_key` → JWT decode (missing/invalid token fails). Tools check `payload['books']` with `PERM_READ` / `PERM_WRITE`.
- Catalog: `ToolDiscovery` scans `@app/Mcp/Tool/*Tool.php` on boot (`DocfsTool` abstract skipped); or explicit `ToolsInterface.tools`.
- Expose via `bash bin/mcp-serve` (stdio) or HTTP `POST /mcp` when the app server is running.

---

## Human read (optional, not LLM Markdown write)

If needed later: plain HTTP `GET` to render `page.md` as HTML/markdown for browsers. Out of scope for the MCP contract.

Binary assets already use HTTP (above) — that is intentional blob bypass, not a general FS API.

## Out of scope

- Public REST `/api/ls|read|write|patch`
- `/api/skill.md` as primary discovery (MCP list_tools replaces it)
- Server-side MD/YAML semantic rewrite
- MCP tools that accept/return asset file bytes
