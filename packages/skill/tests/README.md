# Switon Skill Package Tests

Test suite for `switon/skill`. Every class in `src/` is covered by a unit test.

## Test Structure

```
tests/
└── Unit/          # One file per src class
```

This package has no `Integration/` or `Fixtures/` directory on purpose. The catalog reads a directory and returns rows; there is no transport, database, or container boot to exercise. Fixtures are built inline per test with a temporary directory.

| Test file | Covers |
|-----------|--------|
| `SkillsCatalogTest` | scan, `load`, `reload` merge, `list` filtering, frontmatter coercion |
| `SkillTest` | row factory |
| `SkillVariablesTest` | profile-driven advertisement |
| `SkillLinkExtensionTest` | `[label](skill://id)` pinning |
| `SkillListToolTest` / `SkillLoadToolTest` / `SkillRunToolTest` | the three tools |
| `SkillCommandTest` | `skill:list` output |
| `ServiceProviderTest` | boot scans the catalog |

## Running Tests

From the repository root:

```bash
bash tests/run.sh skill
bash tests/run.sh skill --fast
bash tests/run.sh skill --coverage
```

Run the single file you are changing while iterating:

```bash
bash tests/run.sh skill -- --filter testLoadFallsBackToDirectoryNameForUnusableId
```

From the package root, with dev dependencies installed:

```bash
composer run test:unit
composer run test
```

## Test Quality Standards

- **Strict types:** every file declares `declare(strict_types=1)`.
- **No warnings:** the suite runs under `failOnWarning` and `failOnRisky`.
- **Explicit coverage:** each test class names its subject with `#[CoversClass]`.
- **Cleanup:** tests that write skills remove their temporary directory in `tearDown`.
- **Trusted-input boundaries:** frontmatter is user input, so tests cover malformed values (non-string id, blank id, array description), not only the happy path.

## Contributing

When adding a test:

- Put it under `tests/Unit/` and name it `<Class>Test.php`.
- Use the `Switon\Skill\Tests\Unit` namespace.
- Reach for a temporary directory instead of a committed fixture when the test needs real files.

---

*See [docs/README.md](../docs/README.md) for the package overview.*
