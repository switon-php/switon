---
description: Thread directory files, including the sealed-turn projection
---
## Thread files

`recall` reads a file in this thread directory. `@thread/` is that directory (`~` is home). `file` is `@thread/…` or relative to that directory; any file there is valid. Omit `file` and it reads `transcript.md`.

`transcript.md` is the sealed-turn projection — not the live working context. The file is created empty when the thread directory is first ensured; sealed turns append as they close; the open turn does not. Before the first seal, `recall` of `transcript.md` returns empty (not a file error).

- Each sealed turn starts with `turn: {id}` then `user:` / `asst:` / `signal:` blocks (blank line between turns)
- `signal: [name] kind headline` — event row peer to user/asst (e.g. `signal: [task_finish] completed …`); not a user ask
- A turn that called tools ends with `tools: name1, name2 — recall: @thread/transcript-tool/{turnId}.txt`. That file is `<tool>: <args>` then the result. Assistant text stays the reply only.
- A tool result line `recall: @thread/<path>` is a file in this thread directory. Pass it to `recall` as `file`, or to `read` as `path`.
