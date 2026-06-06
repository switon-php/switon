---
name: sps-audit-doc-proofs
description: Audit documentation as proof-bearing assertions. Use when docs need explicit proof cases, skill-owned proof scripts, and a controlled runnable proof app instead of loose snippet-only validation.
---

# Doc Proofs Audit

## Objective

Treat documentation as a set of assertions that need proof.

Use this skill when the repository needs a stronger document-proof model than:

- prose review
- snippet syntax checks
- ad hoc local sandboxes

This skill is the home for doc-proof orchestration.
It does not replace all existing validators.
It owns the proof model, proof routing, and skill-local proof scripts.

## Positioning

Use this as the specialized proof layer under the broader docs audit flow.

- General entrypoint: `sps-audit-docs`
- Truth gate: `sps-audit-docs-correctness`
- Execution gate: `sps-audit-docs-execution`
- This skill: `sps-audit-doc-proofs`

Think of it as:

- `sps-audit-docs-*` = audit routing and gate decisions
- `sps-audit-doc-proofs` = proof system for document assertions

## What This Skill Owns

### 1. Assertion extraction

Extract proof-worthy claims from docs, such as:

- symbols
- commands
- config keys
- behavior claims
- learning-path checkpoints
- link and anchor integrity

### 2. Proof routing

Map each assertion type to the right proof mechanism instead of treating all claims as fenced PHP snippets.

### 3. Skill-owned proof scripts

If proof orchestration is specific to this skill, put it under this skill's own `scripts/`.
Do not push doc-proof workflow glue into repo-root `scripts/` unless it is clearly reusable across multiple skills.

### 4. Runnable proof app policy

When docs need runnable proof, use a controlled proof app that is versioned as a proof asset, not as an ad hoc build artifact.

## What This Skill Does Not Own

- docs UX judgment
- user-value judgment
- localization quality
- generic Markdown authoring rules
- all-purpose repo utilities that multiple skills will reuse

## Canonical Directory Model

This skill should converge toward this structure:

```text
skills/sps-audit-doc-proofs/
├── SKILL.md
├── scripts/
│   ├── extract_doc_assertions.php
│   ├── route_doc_proofs.php
│   ├── run_doc_proofs.php
│   └── verify_learning_mainline.php
└── references/
    ├── assertion-types.md
    ├── proof-routing.md
    └── proof-app-policy.md
```

Use repo-root `scripts/` only for generic reusable validators such as:

- Markdown link audit
- locale anchor parity
- generic snippet validation

## Proof App Boundary

The runnable proof app should not live as an informal `build/` artifact.

Preferred location:

```text
tests/doc-proofs/tutorial-mainline-app/
```

Current coverage baseline:

- `tutorial` → snippets, links, identifiers, runnable learning-mainline proof app
- `guides` → snippets, links, identifiers, shared learning-mainline runtime baseline
- `getting-started` → links, commands, shared learning-mainline runtime baseline

Use `build/` only for generated runtime artifacts from that proof app, for example:

- SQLite files
- logs
- caches
- temporary vendor state

This keeps proof source under version control while leaving runtime residue disposable.

## Naming Rules

Use names that describe proof role, not vague validation intent.

Prefer:

- `extract_doc_assertions`
- `route_doc_proofs`
- `run_doc_proofs`
- `verify_learning_mainline`

Avoid:

- `check_docs2`
- `tutorial_runner_final`
- `validate_everything`

For proof assets:

- proof app: `tutorial-mainline-app`
- proof suite root: `tests/doc-proofs/`
- generated runtime area: `build/doc-proofs/` or a subdirectory under `build/`

## Relationship With Existing Validators

Reuse current validators instead of rewriting them blindly.

Typical stack:

- `scripts/validate_learning_doc_php_snippets.php`
- `scripts/docs/markdown_link_audit.py`
- `scripts/docs/l10n_anchor_parity.py`
- `sps-verify-identifiers`
- `sps-audit-learning-doc-correctness`
- `sps-audit-tutorial-correctness`

This skill should orchestrate them, narrow their scope, and add proof-app checks where snippet checks are too weak.

## Practical Rule

If a script only exists to make this skill work, keep it inside this skill.
If two or more unrelated skills need the same utility, promote it to repo-root `scripts/`.
