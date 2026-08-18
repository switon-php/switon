# Design Rationale {#design-rationale}

`switon/thread` keeps the working conversation, the open turn, and the append-only transcript in one leaf directory.

## See also {#see-also}

- [Thread Package](README.md) — usage and files
- [FAQ](faq.md#thread-faq) — storage and folding

---

## Core Problem {#core-problem}

One file cannot be both the context sent to a model and the record of what happened. A crash while a turn is open has to reload one copy, not two.

## Primary Goal {#primary-goal}

Store the leaf, seal one turn, and shorten the working context without deleting the transcript.

- **Benefit:** `recall` can read `transcript.md` after older turns have left the working set.
- **Cost:** folding rewrites `conversation.jsonl`. The transcript is the full text.

## Core Choice {#core-choice}

Settled turns live in `conversation.jsonl`. The unsealed tip lives in `turn.json` until `commit` appends it and deletes that file. A matching id on load keeps the sealed copy. `Switon\Thread\ExcerptExtension` folds older turns when the window fills. The agent loop stays outside this package.
