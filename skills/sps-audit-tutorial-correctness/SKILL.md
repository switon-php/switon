---
name: sps-audit-tutorial-correctness
description: Run-oriented acceptance workflow for Switon learning docs. Use when tutorial, guides, or getting-started content must be proven by shared PHP snippet validation and, when needed, runnable sandbox checks.
---

# Tutorial Correctness Acceptance

## When to Use This Skill

- The user asks to **simulate PHP execution**, **prove examples run**, or **avoid teaching broken snippets**.
- The task covers `docs/en/tutorial/**` and may extend to `docs/en/guides/**` or `docs/en/getting-started/**`.
- The user wants stronger proof than a read-only audit.
- The user asks for a runnable tutorial skeleton or sandbox.

## Boundary

- This skill is the **execution-oriented sibling** of `sps-audit-learning-doc-correctness`.
- Use `sps-audit-learning-doc-correctness` for evidence-first read-only review.
- Use this skill when the job requires rerunning validators, fixing examples, or proving runtime paths.

## Required Pre-reads

Read, in order:

1. `standards/README.md`
2. `standards/DOCS_STANDARDS.md`
3. `skills/README.md`
4. `packages/skeleton/AGENTS.md` when sandbox work is in scope
5. `skills/sps-verify-identifiers/SKILL.md`

## Canonical Executable Checks

### Shared snippet validator

Use the shared validator as the primary entrypoint:

```bash
php scripts/validate_learning_doc_php_snippets.php [target ...]
```

Preferred examples:

```bash
php scripts/validate_learning_doc_php_snippets.php tutorial
php scripts/validate_learning_doc_php_snippets.php learning-docs
```

### Runtime proof points

Use real commands when runtime proof is required:

- HTTP boot: `SERVER_TYPE=php php public/index.php`
- CLI discovery: `bash bin/console router:list --json`
- class / contract verification: `bash app-admin/bin/console class:inspect '<FQCN>'`
- targeted package tests when behavior is disputed: `bash tests/run.sh {name}`

### Runnable sandbox

When snippet validation is not enough:

- Prefer `packages/skeleton/` as the donor baseline.
- Stage the runnable proof as a versioned test asset under `tests/doc-proofs/tutorial-mainline-app/`.
- Keep docs text and runnable proof separate.
- Mirror the intended learning path with the smallest working app that proves the documented contract.

## Required Workflow

1. Set scope.
2. Run the shared validator first and capture the full failure set.
3. Run the `sps-verify-identifiers` workflow across all machine-meaningful identifiers in scope.
4. Fix docs or examples with verified Switon-native contracts only.
5. Rerun the validator to zero, or explicitly explain residual failures.
6. Build or refresh the sandbox only when snippet validation does not prove enough.
7. Prove the core path with actual commands or requests.
8. Compare docs against the sandbox and report any remaining mismatch.

## Acceptance Rules

- Do not approve a tutorial path only because the prose sounds reasonable.
- Do not accept Laravel / Symfony / Hyperf-shaped APIs unless verified in current Switon source.
- Do not hide missing prerequisites or hidden setup.
- Do not treat illustrative snippets as runnable unless they truly are, or are clearly labeled partial.
- Prefer a small runnable sandbox over long speculative reasoning.

## Output Format

Use this structure:

### Executed Checks

### Failures

### Fixes Applied

### Residual Risk

## Stop Conditions

Stop when both are true:

1. in-scope validation is clean, or all remaining failures are explicitly explained
2. requested runtime proof points have been executed, or blockers are clearly reported
