# Switon Framework - AI Bootstrap

## Standards

**Start with `standards/README.md`.**

If your tool does not load by index, send to AI:

```
Append standards/README.md into the Standards section of AGENTS.md.
```

- **Standards guide / primary index:** `standards/README.md`
- **Docs optimization baseline:** unless explicitly scoped otherwise, doc-optimization requests default to converging toward the current `docs/en/log/` doc split, weight control, and link style; if that conflicts with `standards/DOCS_STANDARDS.md`, follow `DOCS_STANDARDS.md`
- **Framework development skills:** `skills/README.md`
- **Beacon app-facing built-in skills:** `packages/beacon/skills/README.md`
- **Quick routing:** use `standards/README.md` for standards-led work, `skills/README.md` for repo-root framework-development skill routing, and `packages/beacon/skills/README.md` for Beacon app-facing tasks such as config diagnostics.
- **PHPDoc navigation terms:** `Guidance`（导览）and `Road-signs`（路标） are PHPDoc navigation aids for recurring tasks and mechanism entrypoints, not roadmap terms; see `standards/PHPDOC_STANDARDS.md`
- **App integration rules:** `packages/skeleton/AGENTS.md` § `Switon package rules`
- **PHPDoc rules:** `standards/PHPDOC_STANDARDS.md`
- **PHPStan / extension-installer:** `standards/PHPSTAN_STANDARDS.md`

---

## Bootstrap

- **CLI entry:** `bash app-admin/bin/console`
- You may run `tool:list` for the available tool list and `tool:entry` for the starting class of a topic.

---

## Project-specific rules

- **Response style:** Be concise and conclusion-led. Avoid long, repetitive reasoning.
- **Edit concurrency:** Parallelize reads freely, but do **not** edit the same file in parallel. For a single file, sequence edits: apply one write, then reread if needed before the next write. Parallel writes are allowed only for different, independent files with no overlap risk.
- **Standards docs:** Treat `AGENTS.md` and `standards/*.md` as AI-first rule docs — dense constraints, one short **Why** per doc, no repeated rationale or restated checklists (`standards/README.md` § AI doc shape).
- **Scoping:** Component scope defaults to `packages/{name}/` unless the user explicitly asks for broader changes.
- **Subagents:** When subagents/tasks are available, prefer delegating execution to child agents instead of having the main chat do all file-level work itself. Keep the main chat focused on scoping, assignment, gate decisions, deduplication, and final synthesis. Analysis/review/discovery work may run in parallel when scopes are reasonably separable and the main chat can consolidate results. Editing/execution work should run in parallel only when ownership is clear, edit surfaces are isolated, and no meaningful sequential dependency exists; otherwise reduce concurrency or run serially. Default to at most **4 concurrent** child agents; reduce concurrency when scope is small, overlap risk rises, or the IDE/provider becomes unstable. Append `Reply concisely; lead with conclusions; avoid long reasoning. Save token.` to subagent prompts.
- **Analysis vs modification:** For analysis/review, report only and wait for confirmation before editing. Modify directly only when the user asked to fix, edit, or implement.
- **Design gate:** If a solution adds indirection (global/static state, registries, dispatchers, opaque IDs, thread-local or stack context) or is not the most direct expression of the domain, say so **before** implementing: the problem, a simpler alternative, and **what could be deleted**. On non-trivial refactors, name deletions first; net-new surface (files, classes, public/protected API, runtime fields) needs explicit justification. Wait for user confirmation before building non-obvious layers unless the user explicitly asked for that design.
- **Design alignment:** When API shape, lifecycle, or ownership is unclear, state the model in 1–2 sentences and **wait for confirmation before editing**. Do not answer uncertainty by shipping speculative code (extra properties, registries, branches, renames) to "try it". **Why:** wrong code hides the model and burns review cycles; alignment first is cheaper than revert loops.
- **Correction stop:** When the user questions or pushes back on design or implementation (e.g. 为何/是不是/真的需要吗/又加了)—treat it as **alignment, not approval to edit**. Explain or restate the model first; do not patch until they explicitly ask to fix, edit, or implement.
- **Stated model wins:** Design the user already stated in the same conversation (roles, ownership, public API shape) overrides ad-hoc alternatives. If a proposed change diverges, name the conflict and confirm before editing—not silently extend or replace the agreed model.
- **Component change intent:** Before editing or adding production code under `packages/{name}/src/` (new files, classes, public/protected API, or non-trivial behavior changes), say what it is **for**—one line per new surface (role in the domain, not a diff walkthrough). Same when reporting a completed component change: name new files/classes and their job. Exempt: `tests/`, config-only edits, typos/format-only. **Why:** purpose-first catches wrong abstractions before they land.
- **Code review:** Review line by line; compare sibling implementations; check boundaries, PHPDoc/docs consistency, and meaningful independent tests. Report as `Bugs | Inconsistencies | Improvements`.
- **Diagnostics:** If an issue can be diagnosed by a project command, run it yourself and give the conclusion instead of asking the user to run it.
- **Component tests:** Run component test suites via the repo-root runner `bash tests/run.sh <package>` instead of invoking root-level `phpunit` directly.
- **Troubleshooting:** If a fix is generic and non-obvious, suggest drafting a troubleshooting doc/skill before creating it.
- **User understanding:** If the user seems unclear about a concept or feature, give a short analysis first, then deepen based on feedback.
