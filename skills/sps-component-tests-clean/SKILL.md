---
name: sps-component-tests-clean
description: >-
  Cleans untracked files under monorepo component paths `packages/*/tests` and `packages/*/vendor`
  via scoped Git clean (never repo-wide). Applies when purging test leftovers, coverage, PHPUnit caches,
  per-package `vendor/` installs, or when the user mentions `component-tests-clean`,
  `component-tests-clean-all`, or Makefile targets for component artifact cleanup.
---

# SPS component tests clean

## Scope

- **In scope**: `packages/*/tests` and `packages/*/vendor` (repo root resolved by Git).
- **Out of scope**: repository root `vendor/`, apps, global `git clean`, tracked files.

## Entrypoints

- Script: `scripts/component-tests-clean.sh`
- Makefile:
  - `make component-tests-clean` / `component-tests-clean-preview` — untracked only (ignored stays).
  - `make component-tests-clean-all` / `component-tests-clean-all-preview` — adds `--all-untracked` (`-x`) for ignored paths such as `tests/coverage`, `packages/*/vendor`.

## Commands

Always run from the Switon monorepo Git root unless invoking through Make from that root.

- Dry-run first (recommended):

  ```bash
  bash scripts/component-tests-clean.sh --dry-run
  ```

- Delete **untracked** paths only (respects `.gitignore` for *ignored* items; does **not** remove ignored-but-present dirs such as typical `coverage/` unless using `-x`):

  ```bash
  bash scripts/component-tests-clean.sh
  ```

- Also remove ignored-but-present content under those trees (Git `clean -x`; e.g. `tests/coverage`, `packages/testing/vendor`):

  ```bash
  bash scripts/component-tests-clean.sh --all-untracked
  ```

## Nested Git repos under `vendor`

Some Composer paths contain their own `.git`; plain `git clean -f` **skips** those dirs. This script uses **`git clean -ff`** so per-package `vendor/` trees (and any nested-repo junk under the scoped paths) can be removed.

## Safety rules for agents

1. Prefer **dry-run first** unless the user explicitly asked for immediate destructive cleanup.
2. Warn that **any untracked** path under each `packages/*/tests` can be deleted, including locally added but never-staged tests or fixtures—this is deliberate but irreversible once removed.
3. Do not suggest widening paths beyond `packages/*/tests` and `packages/*/vendor` unless the user requests it explicitly.
