# Switon CDP Package Tests

Unit tests speak to a scripted socket. Live tests start a local Chrome when one is installed.

## Test Structure

```
tests/
├── Unit/          # No Chrome. ScriptedSocket answers CDP.
└── Live/          # Real Chrome. Skipped when the binary is missing.
```

`all` and `test:unit` run `Unit/` only. Live needs a display for headed Chrome and is opt-in.

## Running Tests

From the repository root:

```bash
bash tests/run.sh cdp
bash tests/run.sh cdp --fast
bash tests/run.sh cdp --coverage
```

Live suite:

```bash
bash tests/run.sh cdp -- --testsuite=live
```

From the package root, with dev dependencies installed:

```bash
composer run test:unit
composer run test
```

## Test Quality Standards

- **Strict types:** every file declares `declare(strict_types=1)`.
- **No warnings:** the suite runs under `failOnWarning` and `failOnRisky`.
- **No home paths:** exception text uses `~` for `$HOME`; tests assert that, they do not embed a login name.

## Contributing

When adding a unit test, put it under `tests/Unit/` and drive Chrome through `ScriptedSocket`. Reach for `tests/Live/` only when the assertion needs a real process.

---

*See [docs/README.md](../docs/README.md) for the package overview.*
