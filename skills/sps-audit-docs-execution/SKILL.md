---
name: sps-audit-docs-execution
description: Execution gate for docs. Prove whether the critical learning path is runnable or otherwise executable enough to trust, using shared snippet validation and stronger runtime proof when required.
---

# Docs Execution Gate

## Objective

Answer the second question:

Can the important learning path be executed or otherwise proven, rather than merely sounding plausible?

This gate is narrower than correctness.
It focuses on tutorial, guide, and getting-started paths where broken examples do outsized damage.

## Required Pre-reads

Read, in order:

1. `standards/README.md`
2. `standards/DOCS_STANDARDS.md`
3. `skills/README.md`
4. `skills/sps-verify-identifiers/SKILL.md`
5. `skills/sps-audit-learning-doc-correctness/SKILL.md`
6. `skills/sps-audit-tutorial-correctness/SKILL.md`

## Default Evidence Path

Start with the shared validator:

```bash
php scripts/validate_learning_doc_php_snippets.php [target ...]
```

Preferred logical targets:

- `tutorial`
- `guides`
- `getting-started`
- `learning-docs`

Use stronger runtime proof only when the user asks for it or when snippet validation is not enough.

## Internal Mechanisms

- `sps-audit-doc-proofs` for proof routing and proof-app ownership
- `sps-audit-learning-doc-correctness` for strict read-only proof
- `sps-audit-tutorial-correctness` for runnable acceptance

## What This Gate Must Prove

### 1. Critical PHP snippets validate

Syntax, symbol existence, and method existence must pass for in-scope learning docs.

### 2. Hidden prerequisites are not silently required

If a doc path only works with extra setup, that setup must be explicit or the path fails execution trust.

### 3. Runnable claims are truly runnable

If the doc implies execution rather than illustration, prove it with stronger checks or downgrade the claim.

## Decision Rule

This gate returns one of:

- `pass`
- `fail: snippet validation`
- `fail: runnable path mismatch`
- `fail: hidden prerequisites`
- `partial: stronger runtime proof not requested`

## Output Format

### Executed Proof

### Execution Failures

### Gate Verdict

### Residual Risk
