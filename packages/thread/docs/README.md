# Thread Package {#thread-package}

`switon/thread` stores one thread in a directory and shortens the working context when the model window fills. Choose it when the conversation, the open turn, and the transcript should stay in separate files. The agent loop stays outside this package.

**Key features:**

- **Leaf files:** `conversation.jsonl` holds settled turns. `turn.json` holds the unsealed tip. `transcript.jsonl` and `transcript.md` are append-only. `state.json` and `totals.json` sit beside them.
- **Memory-only:** a null directory writes nothing. Sealing that thread dispatches nothing.
- **Fold:** older settled turns become one short head once input tokens cross the fold line. The newest turns stay whole.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/thread
```

The agent loop is not part of this package. A model catalog is required for the fold, which reads the model's window.

## Quick Start {#quick-start}

`@threads` maps to `@runtime/threads`. Set the thread directory to a fresh leaf, then create it.

```php
$threads->create($thread);
$threads->saveTurn($thread);
$threads->commit($thread);
$threads->load($thread);
```

`create` writes an empty `transcript.md` and `state.json`. `saveTurn` writes the unsealed tip to `turn.json`. `commit` appends that tip to `conversation.jsonl` and the transcript, then deletes `turn.json`.

## Core Concepts {#core-concepts}

### Leaf {#leaf}

A stored thread is the directory in `datadir`. `create` expects that leaf to be fresh. `load` fails when the directory is missing.

`fork` assigns a new id and copies the whole leaf next to the source. A memory-only thread is copied in memory and no directory is created.

### Open turn {#open-turn}

`turn.json` exists while the tip is unsealed. `replaceConversation` rewrites `conversation.jsonl` from the settled turns held in memory and leaves `turn.json` alone.

On load, a `turn.json` whose id is already the last sealed turn is ignored. The sealed copy wins.

### Transcript {#transcript}

`commit` appends the sealed turn to `transcript.jsonl` and a readable block to `transcript.md`. Tool bodies go to `transcript-tool/{turnId}.txt`, and the markdown block points at `@thread/` plus that path. Usage for the leaf is rolled into `totals.json`.

A stored commit then dispatches `Switon\Thread\Event\TurnCommitted`. This package does not dispatch `CompletionAppended`.

### Fold {#fold}

`Switon\Thread\ExcerptExtension` is registered through `extra.switon.agent.extensions`. After a settled turn, it folds when that turn's input tokens reach `line` (default `0.8`) of the model window. `keep` (default `1`) is how many newest settled turns stay whole. The open tip stays as well.

At `0.95` of the window, before the model call, it folds first. If that changes nothing and the folded head fills `catalog` (default one third) of the window, the oldest third of those lines is dropped. Otherwise a settled turn that starts with a user message and ends with an assistant message keeps only those two.

`force` runs the same fold once the last turn has settled, without waiting for the token line.

## Configuration {#configuration}

```yaml
Switon\Thread\ExcerptExtension:
  line: 0.7
  keep: 2
```

`chars` (default `64`) is the length of one folded line.

## Keep in Mind {#keep-in-mind}

- `create` on an existing leaf resets the transcript and state.
- A memory-only `commit` seals the turn in memory and dispatches nothing.
- Folding rewrites the working conversation. The transcript is the full text.

## Next {#next}

- **To debug files and folding:** [FAQ](faq.md#thread-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
