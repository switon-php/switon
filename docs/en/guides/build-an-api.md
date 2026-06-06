# Build an API {#build-an-api}

Use this guide when you want one clear, task-first path for a small JSON API in Switon before you add persistence, authentication, caching, or other broader concerns.
This is the compressed version of tutorial chapters `01` to `03`: route first, then typed input, then service extraction.

## Components in play {#components-in-play}

- **[Kernel](../kernel/README.md)** — bootstraps env, config, and services before request handling starts
- **[HTTP](../http/README.md)** — provides the request/response lifecycle for the application
- **[Router](../router/README.md)** — maps URLs and methods to controller actions
- **[Invoker](../invoker/README.md)** — binds request data into action arguments or [request input objects](../concepts/glossary.md#core-terms)
- **[Validation](../validation/README.md)** — validates input before business logic runs
- **[DI](../di/README.md)** — keeps controllers small and business logic reusable in services

## Recommended build order {#build-order}

1. Start from a normal app bootstrap through [Kernel](../kernel/README.md).
2. Add one route and one controller action.
3. Replace raw request reads with typed input binding.
4. Validate at the input boundary.
5. Move business logic into a service.
6. Stop before persistence, auth, caching, queues, or docs.

## Minimal wiring {#minimal-wiring}

Application-level names like `App\Controller\TaskController`, `CreateTaskRequest`, and `App\Service\TaskServiceInterface` below are illustrative. The Switon framework symbols are the stable part.

Keep application PHP classes under `app/`. Keep framework/service config in root `switon.yml` unless you really need `config/*.php`.

### Controller shape {#wiring-controller}

Start with one explicit route and one JSON response:

```php
namespace App\Controller;

use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/tasks')]
class TaskController extends Controller
{
    #[GetMapping('')]
    public function listAction(): array
    {
        return ['tasks' => []];
    }
}
```

If your app already sets a global router prefix, keep the controller mapping prefix-free instead of duplicating it in the attribute path.

### Input boundary {#wiring-input}

```php
namespace App\Http\Request;

use Switon\Http\Attribute\RequestBody;
use Switon\Validating\Attribute\Length;

#[RequestBody]
class CreateTaskRequest
{
    #[Length(1, 120)]
    public string $title;

    public ?string $note = null;
}
```

```php
namespace App\Controller;

use App\Http\Request\CreateTaskRequest;
use Switon\Http\Controller;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/tasks')]
class TaskController extends Controller
{
    #[PostMapping('')]
    public function createAction(CreateTaskRequest $request): array
    {
        return ['task' => ['title' => $request->title, 'note' => $request->note]];
    }
}
```

For the slower walkthrough of the same move, see [02 — Clean input](../tutorial/02-input.md#02-input). For the owner docs, see [Typed input binding](../http/typed-input-binding.md#typed-input-binding).

### Service boundary {#wiring-service}

```php
namespace App\Controller;

use App\Http\Request\CreateTaskRequest;
use App\Service\TaskServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Controller;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/tasks')]
class TaskController extends Controller
{
    #[Autowired] protected TaskServiceInterface $taskService;

    #[PostMapping('')]
    public function createAction(CreateTaskRequest $request): array
    {
        return ['task' => $this->taskService->create($request->title, $request->note)];
    }
}
```

Under Switon's same-namespace DI convention, pairs like `TaskServiceInterface` and `TaskService` do not need an extra explicit binding block for the first pass.
If the class name differs, the namespace differs, or you have more than one implementation, add explicit DI config in `switon.yml` instead of expecting the convention to guess.
For the slower walkthrough of the same move, see [03 — Service boundary](../tutorial/03-service.md#03-service).

### First route check {#wiring-route-check}

Before you add more layers, prove the public path you are really testing:

```bash
bash bin/console router:resolve /tasks GET
```

If the command does not match the route you expected:

- run `bash bin/console router:prefix` when the controller mapping looks right but the public URL may still carry a global prefix such as `/api`
- run `bash bin/console router:list` when you need to see whether the route was registered at all

If the app adds a global prefix such as `/api`, resolve the full public path instead, for example `bash bin/console router:resolve /api/tasks GET`.
The router prefix itself usually lives in root `switon.yml` or `config/router.php`, depending on your app bootstrap style.
If you are new to the stack, do this check before debugging controller code. A wrong public path is more common than a broken first action.

## What to avoid first {#pitfalls}

- **Authentication** — [JWT](../jwt/README.md), [Authz](../authz/README.md), and [Session](../session/README.md) belong to a later path
- **Persistence design** — choose [ORM](../orm/README.md) or [DB](../db/README.md) in a separate data-focused path
- **Caching** — [Cache](../cache/README.md), [Redis](../redis/README.md), and [HTTP Cache](../http-cache/README.md) solve a later performance problem
- **Async work** — [Queue](../queue/README.md) should not shape the first API path
- **Page rendering concerns** — [Build HTML pages](build-html-pages.md#build-html-pages) is a different path
- **Documentation tooling** — [Add API docs](add-api-docs.md#add-api-docs) comes after the API shape is stable

## Go deeper {#go-deeper}

- **To continue with the ordered tutorial path:** [Tutorial](../tutorial/README.md#tutorial)
- **To build a controller-to-template page path instead of JSON first:** [Build HTML pages](build-html-pages.md#build-html-pages)
- **To go deeper on bootstrap and app startup:** [Kernel](../kernel/README.md)
- **To go deeper on request handling:** [HTTP](../http/README.md) · [Controller](../http/controller.md#controller) · [Router](../router/README.md)
- **To place request-wide hooks, action wrappers, or HTTP failure policy before auth and caching:** [Add cross-cutting HTTP behavior](add-cross-cutting-http-behavior.md#add-cross-cutting-http-behavior)
- **To go deeper on input binding:** [Invoker](../invoker/README.md) · [Typed input binding](../http/typed-input-binding.md#typed-input-binding)
- **To go deeper on validation rules:** [Validation](../validation/README.md)
- **To move business logic into reusable services:** [DI](../di/README.md)
- **To protect the first stable service or request path before broader features land:** [Add testing to a working app](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
- **To add uploads, blob keys, and application-served downloads once HTTP and data boundaries are real:** [Handle file uploads and downloads](handle-file-uploads-and-downloads.md#handle-file-uploads-and-downloads)
- **To add login state, bearer tokens, or protected endpoints next:** [Add authentication](add-authentication.md#add-authentication) · [Session](../session/README.md) · [JWT](../jwt/README.md) · [Authz](../authz/README.md)
- **To protect login or public endpoints once the first routes are real:** [Protect endpoints with rate limits](protect-endpoints-with-rate-limits.md#protect-endpoints-with-rate-limits)
- **To document the API contract once routes and auth shape are stable:** [Add API docs](add-api-docs.md#add-api-docs) · [OpenAPI](../openapi/README.md)
- **To turn the first real routes into a stable runtime, config, and worker-process shape:** [Deploy an app to production](deploy-an-app-to-production.md#deploy-an-app-to-production)
- **To reuse the same service from the console next:** [Add CLI](add-cli.md#add-cli) · [05 — CLI reuse](../tutorial/05-cli.md#05-cli) · [CLI](../cli/README.md)
- **To continue with stored data next:** [Persist data](persist-data.md#persist-data) · [04 — Stored data](../tutorial/04-data.md#04-data) · [ORM](../orm/README.md) · [DB](../db/README.md)
