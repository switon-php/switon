# app-docfs

Open-source **docfs** sample in the Switon monorepo: Git-native docs for technical teams.

Docs stay English. Optional Chinese gloss: 文衍 / Wenyǎn (“writing that grows”).

## Goals

- **docfs** on-disk / Git contract (reusable without this app)
- **MCP tools** as the LLM Markdown maintenance surface (Switon MCP) — **not** a general HTTP FS API
- Binary assets via HTTP (`/books/{book}/assets`) with the **same JWT** as MCP — MCP does not carry asset bytes
- Direct Git remains equivalent
- Showcase Switon app + MCP wiring

## Product constraints

- No business database; Git is SSOT.
- On-disk contract is **docfs** — see `docs/docfs.md` and `docs/file-layout.md`.
- One book = one git repo; book key = slug.
- Required at book root: `index.md` + `AGENT.md`; **flat** docs; MCP uses `book` + `file`; assets only under `assets/{uuid}.{ext}`.
- Path segments: `[A-Za-z\d.-]+` (uppercase OK); doc files end with `.md`.
- Write paths: **direct Git**, **MCP tools** (Markdown), **HTTP assets** (blobs); see `docs/maintenance-modes.md` and `docs/mcp.md`.
- MCP `write`/`patch` require per-file expected `sha`; mismatch → current `sha` in error (re-read body only if needed). `list_files` for sha maps.
- Mutating MCP tools (`write`/`patch`/`mv_file`) and asset HTTP put/delete require `git_message` (commit subject). `mkbook` uses a fixed first-commit message.
- Writes are mechanical (LLM supplies patch params). Reads may parse.
- Optional HTTP later: human Markdown **read** only (assets already use HTTP).
- Permissions (when needed): book-level; MCP and asset HTTP share JWT (`Bearer` / `access_key`).

## App rules

Follow `packages/skeleton/AGENTS.md` for Switon app conventions.
