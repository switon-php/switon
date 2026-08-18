# Thread FAQ {#thread-faq}

Short answers for the thread directory and the folded working context.

> **Prerequisites:** [Thread Package](README.md)

## Getting Started {#getting-started}

### Where does a thread live? {#where}

`@threads` is `@runtime/threads`. The thread's own directory is the leaf. See [Quick Start](README.md#quick-start).

### Which files are written? {#files}

`conversation.jsonl` for settled turns, `turn.json` for the unsealed tip, `transcript.jsonl` and `transcript.md` for the append-only record, `state.json`, and `totals.json`. Tool text for one turn goes to `transcript-tool/{turnId}.txt`.

### What if the thread has no directory? {#memory}

Nothing is written. `commit` still seals the turn in memory and dispatches nothing.

## Working context {#working-context}

### When are older turns folded? {#fold}

After a settled turn whose input tokens reach `line` (default `0.8`) of that model's window. `keep` (default `1`) newest settled turns stay whole. The open tip is not folded. See [Fold](README.md#fold).

### What happens near the end of the window? {#near-full}

Before the model call, at `0.95` of the window, the same fold runs again. If it changes nothing, the oldest third of the folded head is dropped once that head fills `catalog` (default one third) of the window. Otherwise a settled turn keeps its first user message and its last assistant message.

### Does load replay a crash copy of the open turn? {#crash-copy}

No. When `turn.json` has the same id as the last turn already in `conversation.jsonl`, the sealed copy is the one that loads.
