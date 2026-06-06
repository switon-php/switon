---
name: sps-audit-ux
description: Unified entrypoint for component UX audits. Route by mode to interface/docs/defaults/observability/user-value UX checks with one invocation.
---

# Unified UX Audit Router (Component-level, Read-only)

## Objective
Provide one stable entrypoint for UX audits of user-facing component experience.

## Invocation Contract
Use one call shape:
- `component`: target component name (for example `view`)
- `mode`: one of `interface` / `docs` / `defaults` / `observability` / `user-value` / `all`
- `scope`: `staged` / `today` / `all` (default: `staged`)
- `strictness`: `normal` / `high` (default: `normal`)

If `mode` is omitted, default to `all`.

## Natural Language Triggers
When user input is informal, infer `mode` by keywords:
- observability-like words (`log`, `logs`, `logging`, `trace`, `event`, `diagnose`, `troubleshoot`) -> `mode=observability`
- docs-like words (`docs`, `readme`, `faq`) -> `mode=docs`
- defaults-like words (`default`, `out of the box`, `too much config`, `default values`) -> `mode=defaults`
- interface-like words (`api`, `method`, `interaction`, `calling experience`) -> `mode=interface`
- user-value-like words (`user perspective`, `user likes`, `useful`, `feel`, `fun`, `looks good`, `pleasant to read`) -> `mode=user-value`
- if multiple modes match, use `mode=all`

If component is omitted, infer from user text (e.g., `log` -> `component=log`) and confirm only when ambiguous.

## Routing Rules (Deterministic)
- IF `mode=interface`, use `sps-audit-interface-ux` only.
- IF `mode=docs`, use `sps-audit-docs-ux` only.
- IF `mode=defaults`, use `sps-audit-defaults-ux` only.
- IF `mode=observability`, use `sps-audit-observability-ux` only.
- IF `mode=user-value`, use `sps-audit-docs-user-value` only.
- IF `mode=all`, run all five audits and merge deduplicated findings.

## Merge Rules (mode=all)
- One root cause appears once in merged output.
- Keep strongest severity when multiple audits report same root cause.
- Preserve secondary tags as `also-seen-in` notes.
- Keep evidence links from all contributing audits.

## Output Format
### UX Audit Summary
- modes run
- coverage status
- highest-severity findings count

### Unified Findings
- grouped by severity (`P0` -> `P3`)
- each finding includes: primary UX type, evidence, impact, recommendations (`minimal patch` + `structural refactor`)

### Mapping (For triage)
- `Bugs | Inconsistencies | Improvements`

### Verification Footer
- commands/checks run
- checks not run
- residual risk

## Guardrails
- Read-only audit. Do not modify files.
- Use current working tree as source of truth.
- Findings are not capped by count.
