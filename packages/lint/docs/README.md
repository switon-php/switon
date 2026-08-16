# Lint Package {#lint-package}

`switon/lint` checks one file and returns structured diagnostics. Choose it when a write or a tool should report syntax problems without owning a language toolchain. Deeper analyzers stay off until a caller asks for them.

**Key features:**

- **Extension map:** `php`, `js` / `mjs` / `cjs`, `go`, `sh` / `bash`, `py`, `json`, `rb`, `lua`, and `xml` each have a driver.
- **Syntax first:** `php -l`, `node --check`, `gofmt`, `bash -n`, and the other syntax tools run on every check.
- **Deep check:** `$deep = true` adds PHPStan, ESLint, `go vet`, or shellcheck when that tool is found beside the file. A missing tool is skipped.
- **Attach:** `attach()` adds a syntax-only `lint` field. A driver that throws is ignored.
- **Replace a driver:** `drivers` on `Switon\Lint\LinterInterface` replaces an extension. `null` drops it.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/lint
```

Syntax tools must be on `PATH` for the languages you check. JSON and XML stay inside PHP.

## Quick Start {#quick-start}

Inject `Switon\Lint\LinterInterface` and pass an absolute path. The third argument asks for the deeper analyzer.

```php
$report = $linter->lint($path, static fn (): bool => false);
$deep = $linter->lint($path, static fn (): bool => false, true);
```

`$report['tools']` names each checker and whether it found problems. `$report['diagnostics']` is a list of `line`, `severity`, and `message`. A diagnostic may also carry `code`.

The abort callable returns true when the check should stop. A stopped check comes back as an error diagnostic instead of a thrown exception.

## Core Concepts {#core-concepts}

### One file {#one-file}

`lint()` requires a path that exists and has an extension in the driver map. An unknown extension is an error. `supports()` is the same check without running a tool.

`attach()` is for a payload that already changed those files. It checks each supported path with syntax only, then sets `lint` to one report or a list of reports. `attachEnabled: false` makes `attach()` return the payload unchanged.

### Deep check {#deep-check}

PHPStan is used when `vendor/bin/phpstan` is found by walking up from the file. ESLint is `node_modules/.bin/eslint` on that same walk. `go vet` runs when a `go.mod` is found. shellcheck runs when that binary is on `PATH`. The located tool is reused for later files under the same project.

## Configuration {#configuration}

Replace or drop a language in `switon.yml`. A class string replaces that extension. `null` removes it. Extensions left out stay as shipped.

```yaml
Switon\Lint\LinterInterface:
  attachEnabled: true
  drivers:
    php: App\Lint\MyPhpDriver
    lua: null
```

## Keep in Mind {#keep-in-mind}

- A deep check does not fail just because PHPStan, ESLint, `go vet`, or shellcheck is not installed.
- `attach()` does not ask for a deep check, and it does not surface a driver crash.
- The abort callable is the only cancellation signal. This package does not watch an agent.

## Next {#next}

- **To debug a report:** [FAQ](faq.md#lint-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
