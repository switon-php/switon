# docfs

**docfs** = *documentation file system*: the on-disk / Git contract for a Book.

`app-docfs` is an open-source Switon sample: **MCP tools** for LLM maintenance + optional human Markdown read.

## Promise

1. **Git is SSOT** — no business DB for page bodies.
2. **One book = one git repository**; book key = `slug`.
3. **Same bytes** whether you edit via Git or via MCP tools.
4. **Flat docs** at book root (`index.md` + `AGENT.md` required); assets under `assets/{uuid}.{ext}`.
5. **Writes are mechanical** — whole-file `write` or LLM-parameterized `patch`.
6. **LLM Markdown surface = MCP** — not a general HTTP FS API. Binary assets are an HTTP blob bypass (same JWT). See [`mcp.md`](mcp.md).

## Layout (summary)

```text
{book-repo}/
  index.md       # required
  AGENT.md       # required
  *.md           # flat docs
  assets/{uuid}.{ext}   # optional (HTTP assets API or Git)
```

Details: [`file-layout.md`](file-layout.md).  
Write paths: [`maintenance-modes.md`](maintenance-modes.md).  
MCP tools: [`mcp.md`](mcp.md).

## Non-goals for docfs itself

- Nested directory trees for docs (assets/ is the only exception)
- Monetization / proprietary lock-in
- Rich ACL, comments, workflows (app concerns)
- General-purpose REST file API for Markdown (use MCP or Git); asset HTTP is blob-only
