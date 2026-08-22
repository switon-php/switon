# Design Rationale {#design-rationale}

`switon/skill` keeps skill files in one catalog and turns an id into a block. Running a script and following a `skill://` link stay with that catalog.

## See also {#see-also}

- [Skill Package](README.md) — usage and paths
- [FAQ](faq.md#skill-faq) — rows, links, lists, and runs

---

## Core Problem {#core-problem}

Skill text was easy to lose inside a prompt, and easy to disagree about once a desk wanted to add a row without dropping a file into the scan path.

## Primary Goal {#primary-goal}

One id resolves to one row, and the model sees one block for that id.

- **Benefit:** disk rows and runtime rows share the same id. A missing id still has a block, so a link cannot fail silently.
- **Cost:** `reload` will not drop a row that disappeared from disk. That row lasts until the process restarts.

## Core Choice {#core-choice}

Configured paths are scanned for `*/SKILL.md`. Later paths override the same id. `set` publishes a row the scan does not own. `get` fails when the id is absent. `block` returns an unknown-id block instead.

`skill_list`, `skill_load`, and `skill_run` are registered from this package. The agent loop that calls them stays outside. `skill_list` is the catalog index. Skills named on the profile still enter the prompt.

## Trade-Off {#trade-off}

The catalog is a flat map scanned once, not a live index of the filesystem.

- **Benefit:** lookup is a plain array read, and a row can be published at runtime without a file.
- **Benefit:** a missing id still produces a block, so a `skill://` link degrades instead of throwing inside a turn.
- **Cost:** `reload` merges and never prunes, so a deleted file leaves a row until the process restarts.
- **Cost:** a rescan happens on `boot` and on `skill:list`, not on every tool call. A long-running worker does not see a skill created after boot until it rescans.
- **Cost:** overriding an id replaces the whole row. There is no field-level merge, so an override restates every field it needs.

## Deliberate Simplicity {#deliberate-simplicity}

Skill text is data, not code that this package interprets.

- **Scope and description** are read from frontmatter; the rest of `SKILL.md` is handed to the model verbatim.
- **Running a script** is a path join under `scripts/`, with a name that cannot leave that directory. There is no plugin registry and no manifest format.
- **Paths** stay a flat list. Precedence is list order, not a layered override hierarchy.

That keeps a skill auditable as a directory you can read, at the cost of leaving richer composition to whoever assembles the prompt.
