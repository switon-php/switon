# Switon Browser Package Tests

Tests are split by whether they need a real Chrome.

## Test Structure

```
tests/
├── Unit/          # No browser process: a fake page records what the tools asked for
├── Live/          # Real headed Chrome on a real page; skipped when none is available
└── phpunit.xml.dist
```

`Unit/FakePage.php` implements `PageInterface` and records clicks, fills, scrolls, and snapshots, so a
tool can be tested without Chrome. `Unit/HeadedBrowsersStub.php` hands a tool one tab for any agent.

## Running Tests

From the package root:

```bash
# Everything
composer test

# Fast tests only — this is what CI runs
composer run test:unit

# A single test
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --filter testCallClicksTheRef

# Coverage
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --coverage-text
```

In the monorepo, run from the repo root instead: `bash tests/run.sh browser`.

## What the suites cover

- **Unit** — ref mapping, snapshot budgeting and ordering, the tab map, and each tool's argument
  handling and error mapping. No Chrome, no network.
- **Live** — a real page through a real Chrome. It skips when no browser is available, so it is safe
  to run anywhere but is not part of CI.

## Adding a test

- Tool behavior goes in `Unit/Browser*ToolTest.php` and uses `HeadedBrowsersStub`.
- Page behavior that needs a browser goes in `Live/`.
- A snapshot rule (ordering, folding, frame content) goes in `Unit/SnapshotBuilderTest.php` or
  `Unit/SnapshotViewportTest.php`.

---

*See the main README.md for package overview.*
