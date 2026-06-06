---
name: sps-audit-docs
description: Unified docs audit entrypoint. Route documentation audits through three gates: correctness, execution, and quality, while keeping specialized validators as internal mechanisms.
---

# Unified Docs Audit

## Objective

Provide one stable entrypoint for documentation audits.

Do not make the user choose among many overlapping doc audit skills first.
Instead, normalize the request into three gates:

1. `correctness` — are the claims true
2. `execution` — can the critical learning path be proven
3. `quality` — is the documentation strong enough for a slow-moving, high-quality doc set

## Invocation Contract

Use one call shape:

- `scope`: `docs/en/**`, `docs/en/{component}/`, `docs/en/tutorial/**`, or explicit file paths
- `gate`: `correctness` / `execution` / `quality` / `all`
- `strictness`: `normal` / `high` (default: `high` for docs, because doc updates are slow and quality bar is high)

If `gate` is omitted, default to `all`.

## User Selection Protocol

When the user says only “check docs”, “review docs”, “check xx docs”, or another request that is still ambiguous, do not dump many audit names.
Ask one short numbered selection list and allow comma-separated replies such as `1,3,5`.

Recommended menu:

1. `correctness` — verify truth, SSOT readiness, drift, identifiers, and broken links
2. `execution` — verify tutorial, guide, or getting-started paths can actually work
3. `quality` — check structure, signal-to-noise, docs UX, and user value
4. `all` — run all three gates in order
5. `auto-fix low-risk findings` — directly fix low-risk doc issues, or very small low-risk code issues
6. `next` — continue to the next `docs/en` target in lexical order
7. `unattended` — keep advancing with `next` until a blocker or explicit stop

If the user already made the choice clearly, do not ask again.
If the user replies with multiple numbers, merge them into one run plan.

## Gate Routing

- IF `gate=correctness`, use `sps-audit-docs-correctness`
- IF `gate=execution`, use `sps-audit-docs-execution`
- IF `gate=quality`, use `sps-audit-docs-quality`
- IF `gate=all`, run the three gates in this order:
  1. correctness
  2. execution
  3. quality

## Stage Policy

For `gate=all`, treat the gates as quality bars, not three unrelated reports.

- Correctness comes first.
- Execution proves the key learning path after correctness is acceptable.
- Quality is the final bar for a high-value published doc set.

If correctness fails badly, say so clearly before spending tokens on softer judgments.
Do not let UX praise mask false claims.

## Auto-fix Policy

Default mode is still review-first, but low-risk fixes may proceed immediately when the user selects `auto-fix low-risk findings` or clearly authorizes unattended fixing.

### May auto-fix directly

- doc-only issues with low regression risk
- code issues that need no more than about 3 changed lines and remain low risk

### Required safeguards

- if code changes, add or update a meaningful test that proves the fix
- rerun the relevant verification after the edit
- report the fix and verification result; do not stop to ask again for each tiny low-risk item

### Must not auto-fix directly

- behavior-changing edits with unclear blast radius
- edits that need broader refactor or user policy choice
- fixes without a credible verification path

If risk is not clearly low, fall back to report-first.

## Queue and `next` Semantics

When the user says `next`, continue through `docs/en` in lexical order of the top-level doc targets.
Prefer the component or doc-set entrypoint for each target, usually:

- `docs/en/{component}/README.md`
- or the primary file under shared doc sets such as `docs/en/tutorial/README.md`, `docs/en/guides/README.md`, `docs/en/getting-started/README.md`

Report:

- `current`
- `next`
- `remaining`

## Unattended Mode

If the user says `unattended` or otherwise clearly authorizes continuous execution:

- keep using `next` automatically
- continue in lexical order through `docs/en`
- auto-fix only low-risk items under the policy above
- stop only for blockers, non-low-risk decisions, or explicit user interruption

Even in unattended mode, keep short checkpoints in the report so the user can see what was completed.

## Output Format

### Gate Results

### Blocking Failures

### Overall Verdict

### Residual Risk

## Practical Reminders

- Keep specialized validators alive; only the entry surface is unified.
- Prefer gate language over audit sprawl.
- For this repository, default toward stricter judgment rather than convenient approval.
