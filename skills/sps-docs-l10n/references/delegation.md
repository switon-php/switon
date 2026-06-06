# Delegation

Use this reference for **ownership and delegation decisions** only when delegated execution is explicitly allowed again.

## Default status

- This file is **not** part of the normal execution path.
- The current preferred path is single-agent direct localization.
- Do not load this file for ordinary translation work unless the workflow explicitly re-enables delegated execution.

## Responsibilities

- choose scope and package batches
- decide whether work stays local or is delegated
- seed glossary decisions when needed
- run repo-wide gates and final accounting

## When to delegate

- Use delegated execution only when effective quality is expected to be comparable to the direct path for the requested locale and doc type.
- Delegate mainly for multi-package or long-running scopes where parallel ownership materially helps.
- Use at most **4** concurrent subagents.
- Assign each subagent a contiguous related batch rather than random single files.
- Do **not** silently downgrade quality just to gain throughput.
- If the scope is small, high-risk, or terminology-sensitive, prefer direct execution.
- If a delegated path is weaker or unstable, prefer direct execution.
- For broad execution, settle the shared terminology baseline via `references/terms.md` before delegating.

## Pilot check

Run this before scaling any new locale / provider / delegated path:

1. Use the delegated path on `docs/en/core/README.md` only.
2. Check these parts:
   - intro prose
   - `Quick Start` lead-in prose
   - at least one short prose block adjacent to the first code example
3. Compare the result against English and check for:
   - semantic loss
   - terminology drift
   - obviously non-native or weak prose
4. If any of the above appears, stop broad delegation and stay on direct execution.
5. Only scale delegation after the pilot passes.

## Delegation rule

- **Save tokens:** Extract shared translation rules, terminology baselines, and instructions, and pack them directly into the subagent prompt.
- Do **not** point the executor to read `SKILL.md` or `references/*.md` themselves, as this multiplies tool calls and context window costs.
- When possible, let the same executor both scout and execute its owned batch.
