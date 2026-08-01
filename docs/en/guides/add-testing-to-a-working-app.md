# Add testing to a working app {#add-testing-to-a-working-app}

Use this guide when one working app path already exists and you now need a small, reliable testing route before changes spread across HTTP, data, auth, or background behavior.
For a runnable baseline, compare `tests/doc-proofs/tutorial-mainline-app/`.

## Choose the first thing to prove {#thing-prove}

Start from the risk that would hurt you most if it silently regressed.

| First target | Best when | Keep in mind |
|---|---|---|
| service behavior | business rules, branching, mapping, or side effects are the part most likely to change | best default first step; keep assertions on observable outcomes such as returned values, thrown exceptions, emitted events, or persisted state and use `Switon\Testing\TestCase` |
| database behavior | SQL, constraints, repository behavior, or transaction boundaries must be real | use [Testing Database Code](https://github.com/switon-php/db/blob/main/docs/testing.md#testing-database-code); mocking is fine for service collaborators, but it does **not** prove SQL or constraint behavior |
| HTTP-adjacent behavior | request input object shape, authorization markers, or response coordination must stay thin and readable | keep controllers small; if controller tests feel heavy, move behavior down into services first |

A practical first testing slice often includes one service success-path test, one failure-path test, and one database-backed test only where real persistence behavior matters.

## Recommended build order {#build-order}

### 1. Start after one path is already real {#build-real-path}

Testing is most useful when it protects a real boundary, not a speculative design.
Start after one API, CLI, or stored-data path already behaves the way you want.

### 2. Put most application behavior behind a service seam {#build-service-seam}

If the controller or command still owns the real logic, tests will either be noisy or too coupled to transport details.
Move business behavior into a service first, then let HTTP or CLI stay as a thin coordination layer.

### 3. Add one fast test on `Switon\Testing\TestCase` {#build-fast-test}

Use [Testing](https://github.com/switon-php/testing/blob/main/docs/README.md) as the normal first move.
Create the subject with `$this->make()`, keep replacements in `setUpContainer()`, and assert behavior that a reader can understand without tracing private state.

### 4. Add a real database test only where the data boundary is the risk {#build-db-test}

When correctness depends on SQL shape, constraints, or repository behavior, add one focused real-database case through [Testing Database Code](https://github.com/switon-php/db/blob/main/docs/testing.md#testing-database-code).
Do not turn every test into a database test just because the app eventually stores data.

### 5. Keep transport-specific tests narrow {#build-transport-narrow}

HTTP, auth, and CLI paths still matter, but the first test goal is not to simulate every runtime detail.
Prove thin controller or command coordination, then keep most branching and mutation checks below that transport layer.

### 6. Expand only after the first slice is trusted {#build-expand-later}

Once one stable test slice exists, then widen toward auth flows, queue handoff, recurring work, or documentation-facing routes.
A trusted narrow slice is worth more than broad, flaky coverage.

## Minimal wiring {#minimal-wiring}

Use short examples only to show the seams between the shared testing container, the app service boundary, and real database cases.
Application-level names like `UserService`, `RegisterUser`, and `UserRepository` below are illustrative. The Switon testing symbols and runner surface are the stable part.

### Fast service-level path {#wiring-service}

Start with `Switon\Testing\TestCase` and keep the subject on container-managed wiring:

```php
use Switon\Testing\TestCase;

class RegisterUserTest extends TestCase
{
    public function testRegisterCreatesUser(): void
    {
        $service = $this->make(RegisterUser::class);

        $user = $service->handle('dev@example.com');

        $this->assertSame('dev@example.com', $user->email);
    }
}
```

`$this->make()` comes from `Switon\Testing\TestCase`. It resolves the subject through the shared test container, so the service under test is created with the same DI rules its collaborators use in normal runtime code.

Run the fast suite through the shared runner:

```bash
bash tests/run.sh unit
```

If your app does not ship `tests/run.sh`, use the project test entry it already exposes and keep the same split between fast service tests and heavier database-backed tests.

This is the best first move when business behavior matters more than storage details.

### Replace one dependency before the subject is resolved {#wiring-replacement}

When a dependency should become deterministic, replace it in `setUpContainer()`:

```php
use Psr\Log\LoggerInterface;
use Switon\Testing\MockLogger;
use Switon\Testing\TestCase;

class RegisterUserTest extends TestCase
{
    protected MockLogger $logger;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->logger = new MockLogger();
        $this->container->set(LoggerInterface::class, $this->logger);
    }
}
```

`setUpContainer()` is also a hook from `Switon\Testing\TestCase`, not a PHPUnit method you invent from scratch. The test base class creates the container, calls `setUpContainer()`, and only then autowires the test instance and later subjects created through `$this->make()`.

`MockLogger` comes from the `switon/testing` package (`Switon\Testing\MockLogger`). Use it when you want a deterministic logger double instead of the normal runtime logger binding.

That keeps the test close to real DI behavior while making one collaborator predictable.

### Real database path when persistence is the thing you must prove {#wiring-db}

Use [Testing Database Code](https://github.com/switon-php/db/blob/main/docs/testing.md#testing-database-code) when the risk is real storage behavior:

```php
use Switon\Db\ClientInterface;
use Switon\Di\Factory;
use Switon\Testing\TestCase;

class UserRepositoryTest extends TestCase
{
    protected function setUpContainer(): void
    {
        (new Factory([
            'default' => ['uri' => 'sqlite::memory:'],
        ]))->register(ClientInterface::class, $this->container);
    }
}
```

```bash
bash tests/run.sh integration
```

Use this layer when a mock would hide the thing you actually need to trust.

If your app already has a tutorial-style service such as `TaskService::complete()`, keep the failure-path test aligned with the real contract too, for example the returned `found => false` branch.

## What to avoid first {#pitfalls}

Keep the first testing path intentionally narrow.

Avoid these too early:

- **Testing unstable flows first** — protect one path that already works before you chase moving targets
- **Keeping core logic in controllers and then testing controller complexity** — move the logic into services and let HTTP stay thin
- **Using real databases for every test** — reserve them for SQL, constraint, and repository behavior that mocks cannot prove
- **Replacing dependencies after `$this->make()`** — register changes in `setUpContainer()` before the subject or its collaborators are resolved
- **Asserting private state or incidental wiring details** — prefer returned values, persisted state, emitted events, exceptions, or other observable outcomes
- **Treating the first useful test slice as a full coverage plan** — grow from one trusted slice instead of trying to finish everything at once

A beginner should feel this after finishing the first testing path:

> I know which app behavior is protected, why it is tested at that level, and where to widen next.

## Go deeper {#go-deeper}

- **To connect this after the first API or page path:** [Build an API](build-an-api.md#build-an-api) · [Build HTML pages](build-html-pages.md#build-html-pages)
- **To connect this after stored application state becomes real:** [Persist data](persist-data.md#persist-data)
- **To keep using the shared test container and runner surface:** [Testing](https://github.com/switon-php/testing/blob/main/docs/README.md)
- **To prove SQL, repository behavior, or transaction boundaries:** [Testing Database Code](https://github.com/switon-php/db/blob/main/docs/testing.md#testing-database-code) · [DB](https://github.com/switon-php/db/blob/main/docs/README.md)
- **To keep HTTP handlers thin while request behavior grows:** [Add cross-cutting HTTP behavior](add-cross-cutting-http-behavior.md#add-cross-cutting-http-behavior) · [HTTP](https://github.com/switon-php/http/blob/main/docs/README.md)
- **To add login and protected-endpoint behavior after the base path is already testable:** [Add authentication](add-authentication.md#add-authentication)
- **To publish stable routes only after behavior is already trusted:** [Add API docs](add-api-docs.md#add-api-docs)
- **To reuse the same app behavior from the console:** [Add CLI](add-cli.md#add-cli)
