---
name: sps-audit-docs-correctness
description: Truth gate for docs. Prove whether documentation claims are source-backed by combining identifier verification, docs drift checks, learning-doc correctness checks, and English-baseline SSOT checks.
---

# Docs Correctness Gate

## Objective

Answer one question first:

Is this documentation true enough to be trusted?

This gate is the hard barrier.
If runtime-facing claims are wrong, the docs fail here no matter how polished they feel.

## Required Pre-reads

Read, in order:

1. `standards/README.md`
2. `standards/DOCS_STANDARDS.md`
3. `skills/README.md`
4. `skills/sps-docs/SKILL.md`
5. `skills/sps-verify-identifiers/SKILL.md`

## Internal Mechanisms

Use these as building blocks rather than exposing all of them as the first user-facing choice:

- `sps-audit-doc-proofs`
- `sps-audit-docs-ssot`
- `sps-audit-docs-drift`
- `sps-audit-learning-doc-correctness`
- `sps-verify-identifiers`
- `python3 scripts/docs/markdown_link_audit.py --tree docs/en` when English link integrity is in scope

## What This Gate Must Prove

### 1. English baseline is structurally usable

For the scope under review, verify that the English doc set has the expected house shape.

### 2. Machine-meaningful identifiers are real

Classes, methods, commands, config keys, attributes, events, exceptions, packages, and other machine-meaningful identifiers must be source-backed.

### 3. Behavior claims are source-backed

Defaults, merge rules, lifecycle statements, boundaries, flow order, and examples must match source, tests, or authoritative discovery commands.

### 4. Published English docs are SSOT-ready

`docs/en/**` may act as the published-doc baseline only after the earlier points are proven.

### 5. Internal links do not invalidate navigation truth

Broken doc routes and anchors count as correctness failures for published docs.

## Decision Rule

This gate returns one of:

- `pass`
- `fail: implementation drift`
- `fail: English baseline blocked`
- `fail: insufficient proof`

## Output Format

### Verified Truth

### Truth Failures

### Gate Verdict

### Residual Risk
