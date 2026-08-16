# Lint FAQ {#lint-faq}

Short answers for one-file checks.

> **Prerequisites:** [Lint Package](README.md)

## Getting Started {#getting-started}

### How do I check a file? {#check}

Inject `Switon\Lint\LinterInterface` and call `lint()` with the absolute path. See [Quick Start](README.md#quick-start).

### Which extensions are built in? {#extensions}

`php`, `js`, `mjs`, `cjs`, `go`, `sh`, `bash`, `py`, `json`, `rb`, `lua`, and `xml`.

### When does a deeper analyzer run? {#deep}

Only when `lint()` is called with `$deep = true`, and only when that analyzer is found for the file. Otherwise the syntax result is the whole report.

## Reports {#reports}

### What is in a diagnostic? {#diagnostic}

`line`, `severity`, and `message`. Some tools also set `code`, such as a shellcheck id or a PHPStan identifier.

### Why did attach leave the payload unchanged? {#attach}

The path is missing, the extension has no driver, or `attachEnabled` is false. A driver that throws is also ignored, so the write still returns.

### What does an aborted check look like? {#abort}

The checker stops and the report says the lint was aborted. It is not thrown out of `lint()`.
