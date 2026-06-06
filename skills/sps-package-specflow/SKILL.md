---
name: sps-package-specflow
description: Use for Switon framework package work under packages/* when AI must follow a spec-first process: freeze contract and config before code, then implement, test, and sync docs. Applies to component implement/refactor/review tasks; excludes app development.
---

# SPS package specflow

Use this skill only for framework package work in `packages/*`.
Do not use this skill for app development.

## Scope and naming

- Derive `component` from target package folder name:
  - `packages/mail/*` -> `mail`
  - `packages/jwt/*` -> `jwt`
- Create one task workspace:
  - `runtime/specflow/{component}/{task-id}/`
- Use task id format:
  - `YYYY-MM-DD-short-topic`
  - example: `2026-04-07-mailer-compose-default-from`

## Temporary artifacts (must exist before code edits)

Create these files in the task workspace:

- `SPEC_FREEZE.md` (single freeze file, required)
- `ARCHITECTURE.md`
- `TEST_PLAN.md`
- `DOC_SYNC.md`

Use templates from [artifact-templates.md](references/artifact-templates.md).

## Workflow gates

### 1) API inventory section (required, first)

- Fill the `API_FILES` section in `SPEC_FREEZE.md` first.
- Include all interfaces that the caller path touches, not only entry interface.
- Typical check:
  - entry interface
  - request/DTO-facing interfaces
  - collaborator interfaces used by the entry implementation

### 2) Exception catalog section (required, first)

- Fill the `EXCEPTION_CATALOG` section in `SPEC_FREEZE.md`.
- Freeze exception class list first; do not expand message wording/details at this gate.

### 3) API surface section (required)

- Fill the `API_SURFACE` section in `SPEC_FREEZE.md`.
- Freeze only:
  - interface/class entry names
  - method names
  - method signatures (params, return types)
- Do not discuss Road-signs, Guidance, docs wording, or migration text at this gate.

### 4) Contract+config sections (required)

- Fill `CONTRACT` and `CONFIG_MATRIX` sections in `SPEC_FREEZE.md` after API surface freeze.
- Output a concise freeze summary before code edits:
  - APIs/contracts to add/change/remove (already matching `SPEC_FREEZE.md` API sections)
  - required/optional config keys and defaults
  - exception semantics and compatibility policy
- Shape lock rule:
  - after `SPEC_FREEZE.md` API+config sections are frozen, treat component final shape as fixed
  - later phases may implement and verify only; structural changes require Scope Delta first

### 5) Implementation gate

- Edit only files required by frozen contract/config.
- Keep a short changed-file list in `ARCHITECTURE.md`.

### 6) Test gate

- Translate `TEST_PLAN.md` into concrete tests.
- Run package test script:
  - `bash tests/run.sh {component}`

### 7) Docs gate

- Sync docs listed in `DOC_SYNC.md`.
- Keep package README lightweight and detailed docs in `docs/en/{component}/`.
- Road-signs / Guidance text tuning belongs to this gate, not API surface gate.

### 8) Final consistency gate

- Confirm implementation, tests, and docs align.
- Final report format:
  - changed contracts/config
  - test command and result
  - doc files synced
  - open risks

## Scope delta rule (for new requirements after freeze)

When a new requirement appears after spec freeze, do not directly edit production code.
Append a `Scope Delta` section in `SPEC_FREEZE.md` with exactly 3 lines:

1. what changed
2. impacted interface/config/test docs
3. continue now or defer

Then continue implementation.

## Output format rule

- For gates 1-4, output artifacts in fenced code blocks.
- Keep gate output structured and diff-friendly; avoid prose-only summaries.
- Preferred single-file order in `SPEC_FREEZE.md`:
  1) `API_FILES`
  2) `EXCEPTION_CATALOG`
  3) `API_SURFACE`
  4) `CONTRACT`
  5) `CONFIG_MATRIX`
