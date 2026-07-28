# Testing Standards

Test quality only. Scaffolding: `TEST_SETUP.md`.

## Why

- Tests should survive refactors; coupling to internal state or implementation details creates false confidence and blocks legitimate renames.
- A bad test is worse than a missing test when it encodes storage layout instead of behavior.
- Packages split to standalone repos — run tests the same way in monorepo and split checkout (`bash tests/run.sh`).

## Behavior

- Test public, user-visible behavior — not internal state or implementation trivia.
- One behavior per test; AAA; independent; no conditional assertions; no shared order-dependent state.
- Name by behavior + condition: `test{Behavior}With{Condition}()`, `test{Behavior}ThrowsExceptionFor{Condition}()`.
- Avoid names tied to internal methods or storage (`testGetLazyTypeNameWithTwoTypes()`).

**Prioritize:** public API, exceptions, boundaries, cross-component interactions, `ContextAware` state.

**Usually skip direct tests for:** empty exception/event markers, trivial value holders — cover indirectly.

## DI setup

- `make()` for DI-aware instances; register/replace services before `make()`.
- `remove()` before replacing a resolved service.
- `make($class, $params)` may supply constructor args and `#[Autowired]` properties.
- No Reflection to inject dependencies or assert protected fields.
- Protected behavior: public API first; small test subclass second; Reflection only for rare isolation cleanup.

## Doubles

- `createStub()` when call count does not matter; `createMock()` + `expects()` when it does.
- `#[AllowMockObjectsWithoutExpectations]` last resort only. Prefer plain fixtures over heavy mocks.

## Execution

| Item | Rule |
|------|------|
| Run | `bash tests/run.sh <package>` — not root PHPUnit directly |
| Coverage | `bash tests/run.sh <package> --coverage` |
| Suites | `Unit` / `Integration`; not `@group` |
| Cache | `tests/.phpunit.cache` |
| Coverage out | `tests/coverage` |
| phpunit.xml.dist | split-safe bootstrap; strictness/warning policy; shared source excludes |
| Default excludes | `../src/Event`, `../src/Exception` in coverage template |
| Monorepo-only bootstrap | repo-root `tests/run.sh` only — not package-local configs |

Event/data markers and exception marker classes: non-default coverage targets unless a package has a specific reason.
