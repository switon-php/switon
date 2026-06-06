# Git Commit Standards

Monorepo commits before push, PR, and component tagging.

## Why

Components under `packages/{name}/` split out and are tagged independently (`VERSIONING_STANDARDS.md`). Per-component history is what users read — keep component **code/tests** to one component per commit.

## Format

```text
type(scope): imperative subject
```

| type | use for |
|------|---------|
| `feat` | user-visible behavior or public API |
| `fix` | bug fix |
| `test` | tests only |
| `docs` | Markdown / PHPDoc / README |
| `refactor` | behavior-preserving code change |
| `chore` | metadata, lockfiles, maintenance |
| `ci` | workflows, CI scripts |

Optional body after a blank line when the subject cannot carry the **why**. English subjects; ≤ 72 chars when practical.

## Scope and granularity

| Change | Rule |
|--------|------|
| `packages/{name}/` code/tests | one component per commit; scope = that component (`core`, not `packages/core`) |
| same component's docs + code | one commit; do not split `packages/{name}/` from matching `docs/{locale}/{name}/` |
| cross-component docs, l10n sweeps, `standards/`, CI, shared test runner | group by logical outcome; scope optional |

Multi-component code commits: avoid. Split unless the tree cannot compile or test between commits.

```text
fix(event): fail fast on invalid listener signatures
feat(core): add PathAlias cascade docs and registration guard
docs: align event listener fail-fast wording across locales
test: disable xdebug during package phpunit
```

Forbidden subjects: `wip`, `qqq`, `update files`, empty filler.

PR/squash titles use the same shape as the merged change.

## Release

Tags target commits that describe that component's releaseable state. No standalone docs-only commits just to enable tagging (`VERSIONING_STANDARDS.md`).
