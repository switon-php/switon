---
name: sps-docs
description: Use for Switon monorepo documentation work in `docs/`. Write, align, or verify framework Markdown docs. Read `standards/DOCS_STANDARDS.md` first; this skill only adds routing and workflow.
---

# Switon Documentation

## When to Use This Skill

- Writing or editing Markdown in `docs/` or `docs/en/{component}/`
- Creating or updating component docs such as `README.md`, `faq.md`, or `design.md`
- Verifying docs against implementation (`verify docs`)
- Aligning a doc to the house style
- Answering questions about doc workflow or style routing

## Canonical Rules

- Read `standards/DOCS_STANDARDS.md` first.
- Treat `standards/DOCS_STANDARDS.md` as the only authority for Markdown scope, structure, examples, and review criteria.
- Use this skill for routing, reference selection, and verification workflow only.
- Default stack: when docs work touches machine-meaningful identifiers, automatically apply `sps-verify-identifiers` as a companion guard.

## Alignment Workflow

- **Explicit reference wins.** If the user names a reference doc, follow that file.
- **Default reference:** when the user asks to align a doc but does not name a reference file, use the same doc type in `docs/en/log/` as the template:
  - `README.md` → `docs/en/log/README.md`
  - `faq.md` → `docs/en/log/faq.md`
  - `design.md` → `docs/en/log/design.md`
- **Fallback:** if `docs/en/log/` has no same-type file, use `docs/en/log/README.md` for general tone and formatting.
- **Same author, not copy-paste:** mirror layout, pacing, section lead-ins, and pitfall rhythm, but rewrite every sentence for the current component.

## English-first vs localization

- **`docs/en/**` is the authoring surface** for package-facing docs. Shape and split follow `standards/DOCS_STANDARDS.md` and the **`log`** baseline above.
- **Other language trees** (`docs/zh/**`, `docs/ja/**`, …) come **after** the English set is in good shape; use **`sps-docs-l10n`** only for that follow-on work.

## Sub-agents (parallel packages)

When splitting work across sub-agents for **English** doc alignment, **do not** micro-specify every line edit. Give each agent:

1. **Scope:** one component directory `docs/en/{name}/` (or another **disjoint** slice you define).
2. **Baseline:** “Match **`docs/en/log/`** for the same doc types in this directory: `README.md`, `faq.md`, `design.md` — house shape, weight, `**Contents:**` rhythm, `## See also {#see-also}` pattern, pitfall style per `DOCS_STANDARDS.md`.”
3. **Constraints:** no new product semantics; keep anchors and links valid; verify symbols against code if they touch APIs.
4. **Output:** short list of files touched + any English issues that need human decision.

Optional for long sessions: append to the sub-agent prompt: `Reply concisely; lead with conclusions; avoid long reasoning. Save token.`

## Verification Workflow

When the user asks to verify docs:

1. **Scope** — identify the target docs.
2. **Identifier guard** — run the `sps-verify-identifiers` workflow for all machine-meaningful identifiers.
3. **Compare** — verify classes, methods, config keys, commands, package names, and links against code or discovery commands.
4. **Report** — return `Verified | Issues` and use severity levels `critical | minor | recommendation`.

## Practical Reminders

- Keep component docs user-facing.
- Send source-navigation, extension tracing, and deep implementation detail to PHPDoc.
- Prefer fixing the doc or reporting exact mismatches over giving vague style advice.
