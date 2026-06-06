---
name: sps-packages-deps
description: Use for Switon monorepo package dependency maintenance in packages/*. Check, audit, or fix composer.json require/require-dev consistency against actual code usage across packages.
---

# Switon Packages — Dependency Maintenance

## When to Use

- Adding or changing code in a package that uses another package (e.g. new `use Switon\Xxx\...`) — ensure `composer.json` require is updated.
- Auditing or fixing dependencies: "check, maintain, or fix package dependencies" or "dependency consistency".
- Adding a new switon package: ensure dependents list it in require and root `composer.json` if applicable.
- After moving or extracting code between packages: update both source and target package `composer.json`.

**Scope:** Only `packages/*/composer.json`. Root `composer.json` is the app/monorepo aggregate; update it when adding new packages to the repo, not for routine component dependency fixes.

---

## Rules

### 1. Source of truth when maintaining deps

- **Correctness comes from the code.** When verifying or fixing dependencies, the source of truth is **actual usage in the package** (use, extends, implements, type hints). Existing `composer.json` may be wrong or outdated — update it to match what the code really needs.
- **After correction,** `composer.json` is what apps and INTEGRATION_STANDARDS treat as the runtime requirement; the goal is to make it accurately reflect the code.
- **PHP:** `>=8.3`. **ext-*:** Declare only when the package actually uses the extension (e.g. `ext-redis`, `ext-openssl`).

### 2. Cross-package references

- Any `use Switon\Xxx\...`, `extends`, `implements`, type hints, or `@see` pointing to classes in another package must have that package in **require** (or **require-dev** if only used in tests).
- Namespace → package: `Switon\Logging\` → `switon/log`, `Switon\Core\` → `switon/core`, `Switon\Http\` → `switon/http`, etc. Package name = `switon/{dirname}` where `dirname` is the package folder under `packages/`.

### 3. Version constraints

- **switon/*:** Use `*` for monorepo path repos; or `^1.0` when a stable version is published. Keep consistent across packages (prefer `*` in monorepo).
- **psr/*, phpunit/*:** Use stable constraints (e.g. `^2.0`, `^12.0`).

### 4. require vs require-dev

- **require:** Every `use Switon\Xxx\` (and other cross-package reference) in **src/** → that package goes in **require**.
- **require-dev:** Every such reference in **tests/** only → that package goes in **require-dev**. Also: `switon/testing`, `phpunit/phpunit` for test runs.

---

## Workflow: Verify / Fix Dependencies

1. **Identify package:** Determine the package being changed or audited (e.g. `packages/http`).
2. **Find external usage:** `grep -r "^use Switon" packages/<name>/src` and same for `tests/` (and any autoload.files).
3. **Map to composer names:** Map each `Switon\<Package>\` to `switon/<package>` via [references/dependency-rules.md](references/dependency-rules.md).
4. **Assign to require vs require-dev:** References in **src/** → **require**; references only in **tests/** → **require-dev**. Compare with composer.json, add missing, remove unused.
5. **Check PHP and ext-*:** Ensure `php` and any `ext-*` in require match actual usage.
6. **Run install:** From repo root run `composer install` (or `composer update --lock` for minimal change) to verify lockfile and path repos.

---

## Scripts (run from repo root)

| Script | Purpose |
|--------|---------|
| `php skills/sps-packages-deps/scripts/check-deps.php` | Audit: compare code against composer.json using src→require and tests→require-dev, and report MISSING/EXTRA; recognizes `use` and executable fully-qualified `\Switon\...` references while ignoring comments and strings. |
| `php skills/sps-packages-deps/scripts/list-deps.php` | List each package's require / require-dev entries (switon/* only). Add `--table` for a Markdown table. |
| `php skills/sps-packages-deps/scripts/graph-deps.php` | Render the package dependency graph: package→package in-degree/out-degree, top incoming/outgoing, and reverse adjacency; add `--table` for a table and `--include-tests` to include tests edges. |

**check-deps** scans src/, tests/, and autoload.files; it recognizes `use Switon\Xxx\...` and fully-qualified `\Switon\Xxx\...` references in executable code while ignoring comments and strings, which reduces misses such as `extends \Switon\...` and `new \Switon\...`. Confirm differences in code before editing; do not remove `switon/testing` from require-dev based on the script alone.

**graph-deps** is for spotting unintuitive edges: start with `top incoming/outgoing`, then inspect the concrete package `deps / used-by`. By default it only covers src + autoload.files; add `--include-tests` to inspect test edges.

## Quick checks (grep)

- **Per package:** `grep -r "^use Switon" packages/<name>/src` (or `^[[:space:]]*use Switon` if use is indented). Then map first namespace segment (e.g. `Switon\Logging\` → switon/log) via [references/dependency-rules.md](references/dependency-rules.md).
- Scan **autoload.files** too when the package has root-level PHP (e.g. `packages/renderer/helpers.php`): `grep "^use Switon" packages/<name>/helpers.php`.

---

## Reference

- **Dependency rules and checklist:** [references/dependency-rules.md](references/dependency-rules.md) — namespace→package map, common mistakes, checklist before submit.
