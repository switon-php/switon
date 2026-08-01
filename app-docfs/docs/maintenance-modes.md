# docfs — maintenance modes

Format / OS: **docfs**. Sample app: **app-docfs** (Switon).  
Layout contract: [`file-layout.md`](file-layout.md). Overview: [`docfs.md`](docfs.md).

## Ideal model

**Same docfs book repo for everyone.** Maintenance is allowed in two equivalent ways:

```text
                    ┌─ A. LLM / agent edits the Git repo directly
Human ↔ chat ──────┤
                    └─ B. LLM calls MCP tools (same repo bytes)

Either path → Git commit(s) → optional read-only Markdown view
```

- **A (direct Git)** — local clone / IDE agent. No service required.
- **B (MCP)** — remote books; Switon MCP tools for Markdown (`list_books`/`list_files`/`read`/`write`/`patch`/…). **No general REST FS API.** Binary assets use HTTP blob API (same JWT) or Git.

Neither path is a second source of truth. **Git remains SSOT.**

## Write discipline (MCP / Git)

Files are **LLM-authored**. Tools do not semantically rewrite documents.

| | Write (`write` / `patch`) | Read (`read` / `list_files` / `list_books`) |
|--|--|--|
| Allowed | Full file bytes; or mechanical patch (LLM params) | Parse / line arrays; human HTML later |
| Forbidden | Parse AST → edit → dump | — |

See [`mcp.md`](mcp.md) for tool args. Expected `sha` is per-file; `list_files` for sha maps.

## What must stay shared

| Concern | Rule |
|---------|------|
| Paths | `index.md`, `AGENT.md` required; flat `*.md`; `assets/{uuid}.{ext}` via HTTP assets API or Git |
| Body format | Markdown |
| History | Git commits; MCP mutating tools take `git_message` (mkbook fixed) |

If direct Git and MCP diverge in layout or semantics, the contract is broken.

## Concurrency

- **Direct Git:** normal git merge / rebase / PR review.
- **MCP:** per-file expected `sha` on `write`/`patch`; mismatch → current `sha` in error. `list_files` = full sha map.

## Experience (still needed)

1. **Read experience = Markdown reading** — optional HTTP/UI for humans (not the LLM write path).
2. **Maintain experience** — chat → Git tools or **MCP**; humans review diffs/PRs when it matters.

The sample is **docfs + MCP + optional Markdown reader** — not a REST CMS.

## When to use which

| Situation | Prefer |
|-----------|--------|
| Local clone, agent in IDE | Direct Git (A) |
| Remote MCP agent | MCP tools (B) |
| CI bot with MCP | MCP tools (B) |
| Offline / air-gapped | Direct Git (A) |

## Non-goals

- Public REST FS API / `/api/skill.md` as primary surface (MCP list_tools instead)
- A write path that produces a different on-disk shape than direct Git
