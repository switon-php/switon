---
name: sps-audit-learning-doc-correctness
description: Strict read-only correctness audit for docs/en/tutorial, docs/en/guides, and docs/en/getting-started by combining shared PHP snippet validation with evidence-first source and discovery-command checks.
---

# Learning Docs Correctness Audit

## When to Use This Skill

- The task is to audit `docs/en/tutorial/**`, `docs/en/guides/**`, or `docs/en/getting-started/**` for correctness.
- The user wants a **read-only** review before approving or editing docs.
- The risk is wrong symbols, hidden prerequisites, broken sequence, or borrowed Laravel / Symfony / Hyperf contracts.

## Boundary

- Stay **read-only**.
- Treat clean snippet validation as **necessary but not sufficient**.
- If the user asks to fix files, simulate runtime, or build a runnable skeleton, switch to `sps-audit-tutorial-correctness` or use both skills together.

## Required Pre-reads

Read, in order:

1. `standards/README.md`
2. `standards/DOCS_STANDARDS.md`
3. `skills/README.md`
4. `skills/sps-verify-identifiers/SKILL.md`

## Canonical Validator

Run the shared validator first:

```bash
php scripts/validate_learning_doc_php_snippets.php [target ...]
```

Logical targets:

- `tutorial`
- `guides`
- `getting-started`
- `learning-docs` — default aggregate target

Examples:

```bash
php scripts/validate_learning_doc_php_snippets.php tutorial
php scripts/validate_learning_doc_php_snippets.php docs/en/tutorial/04-data.md
php scripts/validate_learning_doc_php_snippets.php docs/en/guides docs/en/getting-started
```

What the validator proves:

- PHP syntax for fenced `php` blocks
- existence of referenced `Switon\*` and `Psr\*` symbols
- method existence when a call resolves to a known real type or a snippet-defined type extending / implementing one

## Required Workflow

1. Resolve scope from explicit paths or logical targets.
2. Run the shared validator across the full scope; do not stop at the first failure.
3. Run the `sps-verify-identifiers` workflow across all machine-meaningful identifiers in scope.
4. Extract every machine-meaningful claim that the validator does not prove by itself:
   - commands, config keys, routes, request / response shapes, defaults, step expectations
5. Verify claims against source, discovery commands, or targeted package tests.
6. Check chapter continuity and hidden prerequisites across adjacent pages.
7. Report only evidence-backed findings and separate them by severity and type.

## Guardrails

- Do not approve docs because the prose feels plausible.
- Do not accept near-Switon identifiers or borrowed framework idioms as evidence.
- Do not accept semantically misleading identifier names when repository conventions already define the expected naming slot.
- Do not silently assume runtime behavior that was not proven.
- Do not treat partial snippets as runnable unless the page says so clearly.
- Prefer a false negative over approving a hallucinated contract.

## Finding Shape

Each finding should include:

- severity: `P0` / `P1` / `P2` / `P3`
- type: `API` / `Config` / `Behavior` / `Example` / `Identity` / `Sequence` / `Link`
- docs evidence: file + line
- code evidence: file + line, test, or command output
- impact: what the learner would misunderstand or fail to do
- fix direction: one short repair direction

## Output Format

Use this top-level structure:

### Bugs

### Inconsistencies

### Suggested Improvements

Then finish with:

- `Residual risk: ...`
- `Verification summary: ...`

## Stop Conditions

Stop when all in-scope files are checked and every factual concern is either proven, reported with evidence, or explicitly left in `Residual risk`.
