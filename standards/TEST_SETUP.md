# Test Setup Guide

For package `tests/` scaffolding only. Naming, AAA, mocks, Reflection rules, and review quality are out of scope here.

## Target layout

Create package tests under `packages/{package}/tests/`.

```text
packages/{package}/
└── tests/
    ├── phpunit.xml.dist
    ├── TestCase.php
    ├── Unit/
    ├── Integration/         (optional)
    ├── Fixtures/            (optional)
    └── README.md            (optional, package-specific notes)
```

Package-level `tests/README.md` titles use `# Switon {Display Name} Package Tests`, matching `packages/{name}/README.md`. Optional `tests/Fixtures/README.md` uses `# Switon {Display Name} Package Test Fixtures`.

## Required files

### `.gitignore`

- Every publishable framework package under `packages/{package}/` should keep the same package-level ignore baseline, even when some paths do not exist yet.
- Default component template:

```text
/vendor
/composer.lock
/docs
/runtime
/tests/.phpunit.cache
/tests/coverage
.DS_Store
```

- Keep test-artifact ignores at package root; do not add package-local `tests/.gitignore` files for PHPUnit cache or coverage output.
- Treat `/runtime` as part of the standard component ignore boundary, not as a package-specific exception.
- `packages/skeleton/` is an app skeleton, not a normal framework component; it may keep its own app-oriented ignore rules.

### `tests/phpunit.xml.dist`

- Must live inside `tests/`.
- Default package bootstrap should be `../vendor/autoload.php`.
- Keep test suites aligned with local `Unit/` and `Integration/` directories.
- Point coverage source to `../src` from the `tests/` directory.

### `tests/TestCase.php`

- Prefer extending the shared `Switon\Testing\TestCase`.
- Add package-specific container setup in clearly named hooks such as `setUpContainer()`.
- Keep package test setup centralized here instead of repeating it in many test files.

## Optional directories

### `tests/Integration/`

- Add only for tests that cross component boundaries, need more runtime wiring, or are meaningfully slower than unit tests.

### `tests/Fixtures/`

- Add when test doubles, helper classes, or reusable builders are shared across multiple test files.
- Prefer PSR-4-friendly fixture files:
  - one class, trait, enum, or interface per file
  - use subdirectories only when the namespace needs them
- Reserve manual preload files for true special cases only, such as namespaced function overrides or external stub bootstraps.

## Setup flow

1. Create `tests/` with `Unit/`.
2. Add `phpunit.xml.dist` and `TestCase.php`.
3. Point PHPUnit bootstrap at `../vendor/autoload.php`.
4. Add `Integration/` and `Fixtures/` only when needed.
5. Run `bash tests/run.sh {package}` before submitting.

## Live examples

- Recent package `tests/` directories are the best in-repo examples.
