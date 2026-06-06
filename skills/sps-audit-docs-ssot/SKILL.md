---
name: sps-audit-docs-ssot
description: Unified SSOT audit for `docs/en/**`. Prove whether English docs are source-ready as the published-doc baseline by checking code-backed claims, docs drift, learning-doc executability, link integrity, and locale parity.
---

# Docs SSOT Audit

## Positioning

Use this as a specialized correctness sub-check inside the broader docs audit flow.
For a general documentation audit entrypoint, prefer `sps-audit-docs`.

## Objective

Verify whether `docs/en/**` is acting as the correct single source of truth for published documentation.

This means:

- English docs are the baseline for published doc structure, wording, and locale inheritance.
- Code, PHPDoc, tests, and authoritative discovery commands remain the source of truth for runtime behavior and machine-meaningful identifiers.
- A page in `docs/en/**` is **SSOT-ready** only when it is both:
  - the authoritative English doc baseline for downstream locales
  - still faithful to the current framework implementation

If English docs drift from code, they stop being SSOT-ready even if locale files still mirror them perfectly.

## When to Use This Skill

- The user asks whether `docs/en/**` is really the SSOT.
- The task is to validate the English doc tree before localization, bulk updates, or doc regeneration.
- The user wants one audit that combines identifier proof, docs drift, learning-doc validation, links, and locale parity.
- The user wants a clear verdict instead of several disconnected doc checks.

## SSOT Boundary

Treat SSOT as a layered contract, not a slogan:

1. **Runtime truth** lives in source, PHPDoc, targeted tests, and authoritative discovery commands.
2. **Published English truth** lives in `docs/en/**` only after runtime-facing claims are proven against layer 1.
3. **Localized truth** must inherit from `docs/en/**`, not invent parallel semantics.

Do not treat `docs/en/**` as the source of truth for implementation details that are only proven in code.
Do treat `docs/en/**` as the source of truth for published English doc structure, anchors, page split, and locale inheritance once validated.

## Required Pre-reads

Read, in order:

1. `standards/README.md`
2. `standards/DOCS_STANDARDS.md`
3. `skills/README.md`
4. `skills/sps-docs/SKILL.md`
5. `skills/sps-verify-identifiers/SKILL.md`

Then stack these skills as needed:

- `sps-audit-docs-drift` for component docs vs package/code drift
- `sps-audit-learning-doc-correctness` for read-only learning-doc correctness
- `sps-audit-tutorial-correctness` when runnable proof is required

## Validation Layers

Run the audit in this order. Do not skip earlier layers just because later layers look clean.

### 1. English baseline coverage

Verify the English doc tree has the expected baseline pages for the scope under review.

- Component docs: `README.md`, `faq.md`, `design.md`
- Learning docs: `docs/en/tutorial/**`, `docs/en/guides/**`, `docs/en/getting-started/**`
- Supporting foundations: `docs/en/README.md`, `docs/en/concepts/**`, and other explicitly requested shared docs

If the English page split is missing or structurally inconsistent, fail SSOT readiness before checking localization.

### 2. Identifier and behavior proof

Run the `sps-verify-identifiers` workflow on all machine-meaningful identifiers and behavior claims in scope.

Verify:

- classes, interfaces, attributes, methods, config keys, commands, events, exceptions, and package names
- defaults, merge rules, request flow, lifecycle timing, and responsibility boundaries when the doc claims them

If a runtime-facing claim is not code-backed, English docs are not SSOT-ready.

### 3. Docs drift vs implementation

For component docs, run the `sps-audit-docs-drift` workflow to classify drift:

- `API`
- `Config`
- `Behavior`
- `Example`
- `Boundary`
- `Link`

If English docs and code disagree, record runtime truth from code and mark English as drifted.

### 4. Learning-doc executability

For `docs/en/tutorial/**`, `docs/en/guides/**`, and `docs/en/getting-started/**`, run the shared validator:

```bash
php scripts/validate_learning_doc_php_snippets.php [target ...]
```

Typical targets:

- `tutorial`
- `guides`
- `getting-started`
- `learning-docs`
- explicit file or directory paths under `docs/en/**`

Use `sps-audit-learning-doc-correctness` for evidence-first review.
Use `sps-audit-tutorial-correctness` only when runnable sandbox or runtime proof is required.

### 5. Link and anchor integrity

Audit Markdown links inside English docs:

```bash
python3 scripts/docs/markdown_link_audit.py --tree docs/en
```

English docs cannot act as SSOT if internal links, cross-file anchors, or doc routes are broken.

### 6. Locale inheritance proof

When locale scope is requested, prove that locales inherit from English instead of silently diverging:

```bash
python3 scripts/docs/l10n_anchor_parity.py --locale <locale>
```

This proves:

- every English Markdown file has a locale counterpart
- ordered `{#...}` anchor sequences match pairwise

Anchor parity is necessary but not sufficient. It proves inheritance structure, not factual correctness.

## Decision Rules

Use these verdicts:

- `SSOT-ready`
  - English docs are code-backed for the audited scope
  - English links/anchors are clean
  - learning-doc checks pass for in-scope pages
  - locale parity is clean when locale scope is requested
- `English baseline blocked`
  - English structure, links, or learning-doc execution fails before locale comparison is meaningful
- `Implementation drift`
  - English docs conflict with code, PHPDoc, tests, or discovery commands
- `Locale drift`
  - English docs are acceptable, but one or more locale trees no longer inherit cleanly
- `Partial proof only`
  - some layers were not requested or could not be verified; list them under residual risk

Important rule:

- If English disagrees with code, code wins for runtime truth and the English doc fails SSOT readiness.
- If locale disagrees with English, English remains the candidate SSOT and the locale fails inheritance.

## Output Format

Report with this top-level shape:

### Verified Layers

### SSOT Failures

### Verdict

### Residual Risk

For each failure, include:

- layer
- evidence
- impact
- fix direction

## Practical Reminders

- Do not call something SSOT just because it is centrally located.
- Do not call locale parity a correctness proof.
- Do not call a page source-backed unless behavior claims, not just symbol names, were verified.
- Prefer failing SSOT readiness over approving a plausible but unproven English baseline.
