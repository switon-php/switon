---
name: sps-docs
description: Use for Switon monorepo documentation work. Write, align, or verify framework Markdown docs. Read `standards/DOCS_STANDARDS.md` first; this skill only adds routing and workflow.
---

# Switon Documentation

## When to Use This Skill

- Writing or editing component docs in `packages/{name}/docs/`
- Writing or editing narrative docs in `docs/en/**` (concepts/guides/tutorial/…)
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
- **Default reference:** when the user asks to align a doc but does not name a reference file, use the same doc type in `packages/log/docs/` as the template:
  - `README.md` → `packages/log/docs/README.md`
  - `faq.md` → `packages/log/docs/faq.md`
  - `design.md` → `packages/log/docs/design.md`
- **Fallback:** if `packages/log/docs/` has no same-type file, use `packages/log/docs/README.md` for general tone and formatting.
- **Same author, not copy-paste:** mirror layout, pacing, section lead-ins, and pitfall rhythm, but rewrite every sentence for the current component.

## English-only published docs

- Component SSOT is `packages/{name}/docs/**`. Narrative stays under `docs/en/**`.
- No `docs/zh-CN`, `docs/ja-JP`, or other locale trees.
- Cross-package and package-landing links use split-repo GitHub URLs: `https://github.com/switon-php/{name}/blob/main/docs/...`.
- Same-package links stay relative (`faq.md`, `design.md`).

## Sub-agents (parallel packages)

When splitting work across sub-agents for doc alignment, **do not** micro-specify every line edit. Give each agent:

1. **Scope:** one component directory `packages/{name}/docs/` (or another **disjoint** slice you define).
2. **Baseline:** “Match **`packages/log/docs/`** for the same doc types in this directory: `README.md`, `faq.md`, `design.md` — house shape, weight, section rhythm, `## See also {#see-also}` pattern, pitfall style per `DOCS_STANDARDS.md`.”
3. **Constraints:** no new product semantics; keep anchors and links valid; verify symbols against code if they touch APIs; no links to `docs/en/guides` from component `## Next`.
4. **Output:** short list of files touched + any English issues that need human decision.

Optional for long sessions: append to the sub-agent prompt: `Reply concisely; lead with conclusions; avoid long reasoning. Save token.`

## Verification Workflow

- Prefer project discovery commands when an identifier can be checked that way.
- Report invented or unverified identifiers as blockers.
- Keep cross-package links on the split-repo GitHub form above.
