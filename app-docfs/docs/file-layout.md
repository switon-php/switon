# docfs — Book file layout

Status: draft  
App constraints: `AGENTS.md`.  
Format / OS: **docfs** — see [`docfs.md`](docfs.md).  
Sample app: **app-docfs**.  
Write paths: [`maintenance-modes.md`](maintenance-modes.md).

## 1. Goals

- Git is the only source of truth; no business database
- One book = one git repo; book key = `slug`
- **Flat docs** — `{file.md}` at book root only (no nested doc dirs)
- Two required files: **`index.md`** + **`AGENT.md`**
- Assets under **`assets/{uuid}.{ext}`** (only allowed subdirectory)

## 2. Book repository layout

```text
{@books}/{book-slug}/     # = one git repo
  index.md                  # required — entry / TOC
  AGENT.md                  # required — how agents maintain this book
  overview.md               # other flat .md files
  assets/                   # auto-created on first asset put
    550e8400-e29b-41d4-a716-446655440000.png
```

| Path | Role |
|------|------|
| `index.md` | Book entry / table of contents |
| `AGENT.md` | Book-local agent notes |
| `*.md` | Documents (flat only) |
| `assets/{uuid}.{ext}` | Blobs via HTTP `/books/{book}/assets` (or direct Git) |

`mkbook` creates the git repo and seeds empty `index.md` + `AGENT.md` only. Response: `book` + `entries` (with file shas).

## 3. Naming

- Docs: MCP args `book` + `file` (e.g. `index.md`); names `[A-Za-z\d.-]+` (uppercase OK)
- Assets: upload original name (for **extension** only); disk file is `{uuid}.{ext}`
- Rename docs: `mv_file` with `to`; omit `to` to delete (not `index.md` / `AGENT.md`)
- Update `index.md` when adding or renaming pages

## 4. Concurrency and `sha`

- `sha` = SHA1 of **file bytes**
- Doc mutations and asset HTTP `DELETE` require expected `sha` when applicable
- `list_files` refreshes all file shas; book `commit` is optional metadata

## 5. Assets

- HTTP: `POST /books/{book}/assets` (multipart `file` + `git_message`; same JWT as MCP)
- `POST` → generate UUID, keep ext, write `assets/{uuid}.{ext}`, mkdir `assets/` if needed
- Link in Markdown using returned `file`: `![…](assets/{uuid}.png)`
- Deleting an asset does **not** rewrite Markdown references
- MCP does **not** transfer asset bytes

## 6. Discovery

- Start at `index.md` (and `AGENT.md` for maintainers)

## 7. Non-goals

- Nested doc dirs, nav YAML, business DB, server-side MD rewrite
