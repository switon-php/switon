# Scripts Directory

Utility scripts for monorepo maintenance and testing.

## test-full.sh / test-summary.sh

Run all package tests from monorepo root directory. These scripts share the same implementation:
- `test-full.sh`: Shows detailed/full test output
- `test-summary.sh`: Shows only summary lines (symlink to `test-full.sh`)
- `test.sh`: Short alias for `test-full.sh` (symlink)
- `test-brief.sh`: Alias for `test-summary.sh` (symlink)

### Usage

```bash
# Run all package tests (detailed/full output)
./scripts/test-full.sh
./scripts/test.sh  # Short alias

# Run all package tests (summary only)
./scripts/test-summary.sh
./scripts/test-brief.sh  # Alias

# Run tests for specific package (first argument as package name)
./scripts/test-full.sh di
./scripts/test-summary.sh di

# Run tests for specific package (using --package option)
./scripts/test-full.sh --package=sharding
./scripts/test-summary.sh --package=sharding

# Run only unit tests for all packages
./scripts/test-full.sh unit
./scripts/test-summary.sh unit

# Run with PHPUnit options
./scripts/test-full.sh --coverage-html=coverage/
./scripts/test-full.sh --stop-on-failure
./scripts/test-full.sh --verbose
```

### Differences Between Modes

**test-full.sh (Full Output Mode)**
- Shows full test output for each package
- Includes PHPUnit installation check
- Shows detailed test summary at the end (✓/✗)
- Supports `--parallel` and `--failed` options
- Uses `set -e` (stops on first error)

**test-summary.sh (Summary Mode)**
- Shows only summary lines (OK/FAILURES with test and assertion counts)
- Clean output: No detailed test case output
- Continues on errors (doesn't use `set -e`)

### Common Features

- **Automatic discovery**: Finds all packages with `tests/phpunit.xml.dist`
- **Uses package's run.sh**: Each package's test runner is used (respects package-specific setup)
- **Error handling**: Continues running other packages even if one fails
- **Summary report**: Shows which packages passed/failed at the end
- **Exit code**: Returns 0 if all pass, 1 if any fail (CI/CD friendly)
- **Package name as first argument**: If first argument doesn't start with `--` and isn't `unit`/`integration`, it's treated as package name
- **Concurrency rule**: One full test runner at a time; package-level parallelism is fine, but do not run the same package through multiple full runners at once.

### Example Output (test-summary.sh)

```
Running tests for 5 package(s)...

core                           OK (151 tests, 384 assertions)
di                             OK (204 tests, 478 assertions)
event                          OK (62 tests, 133 assertions)
http                           OK (89 tests, 245 assertions)
orm                            OK (312 tests, 856 assertions)

All packages passed!
```

### Why Batch Script Instead of phpunit.xml.dist?

**Batch Script (Recommended)** ✅
- ✅ Each package has its own `bootstrap.php` and autoloader
- ✅ Respects package-specific test configuration
- ✅ Better error isolation (one package failure doesn't stop others)
- ✅ Can show progress and summary for each package
- ✅ Supports selective testing (`--package=name`)
- ✅ Works with existing package test infrastructure

**phpunit.xml.dist (Not Recommended)** ❌
- ❌ Would need a single bootstrap.php for all packages (complex)
- ❌ Can't easily handle different autoloaders per package
- ❌ One package failure stops all tests
- ❌ Less flexible for package-specific configurations

## docs/markdown_link_audit.py

Audit Markdown links in one or more documentation trees.

### Usage

```bash
# Check internal file links and anchors in English docs
python3 scripts/docs/markdown_link_audit.py --tree docs/en

# Check internal file links and anchors in Japanese docs
python3 scripts/docs/markdown_link_audit.py --tree docs/ja

# Audit the whole docs tree and also verify external HTTP(S) links
python3 scripts/docs/markdown_link_audit.py --tree docs --check-external
```

### What it checks

- Relative Markdown links to existing files
- Fragment-only links such as `#summary`
- Cross-file anchors such as `README.md#quick-start`
- Optional external HTTP(S) links when `--check-external` is enabled

## check-internal-deps.php

Compare each package's `src/` `use Switon\...` with its `require`. Exits with status 1 when a require is missing or unused.

```bash
php scripts/check-internal-deps.php
```

## package-deps.php

List each package's `switon/*` dependencies and print them in waves. Exits with status 1 on a cycle. `--dev` includes `require-dev`.

```bash
php scripts/package-deps.php
php scripts/package-deps.php --dev
```

## sync-framework-links.sh

Build `framework/` for PhpStorm inspection: `framework/Switon/{Segment}` symlinks, generated `composer.json` + `phpstan.neon`, then `composer install` in `framework/`.

- **autoload.psr-4:** only `Switon\{Segment}\` → `Switon/{Segment}/` (from package symlinks).
- **require:** copies monorepo `psr/*` constraints (FIG interfaces resolved via `framework/vendor` after install).
- **require-dev:** `phpstan/phpstan`, `phpstan/extension-installer` (from monorepo root).

```bash
bash scripts/sync-framework-links.sh
composer framework:sync
bash scripts/sync-framework-links.sh --dry-run
bash scripts/sync-framework-links.sh --no-install
cd framework && composer phpstan
```

Generated under `framework/` (only `vendor/`, `Switon/`, lock, cache are gitignored). PhpStorm:

- Open the **monorepo** root, then enable **PHPStan** quality tool: executable `framework/vendor/bin/phpstan`, config `framework/phpstan.neon` (after `composer framework:sync`).
- `vendor/phpstan/phpstan` is **PHAR-only by upstream design** (no `src/` to browse); analysis still works. Do not mark `phpstan.phar` as a source root.
- Exclude `packages/`, repo `vendor/`, `app-admin/` from indexing; use `framework/Switon/` as the Switon source view.
