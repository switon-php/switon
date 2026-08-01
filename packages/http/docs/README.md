# HTTP Package {#http-package}
`switon/http` gives Switon apps one controller-based HTTP boundary with request input, response shaping, lifecycle events, and runtime adapters.

**Key features:**

- **Controller actions:** map routes to methods with `#[RequestMapping]` and HTTP verb attributes from `switon/router`
- **Request boundary:** read route, query, body, headers, files, and attributes through `Switon\Http\RequestInterface`
- **Response boundary:** shape status, headers, cookies, redirects, downloads, and streaming through `Switon\Http\ResponseInterface`
- **Typed input binding:** bind body, query, or merged request data into typed input classes with `#[RequestBody]`, `#[RequestQuery]`, and `#[RequestData]`
- **Lifecycle hooks:** attach cross-cutting behavior to request and response events instead of spreading it across actions
- **Filter layer:** request-wide filters own cross-cutting behavior like request IDs, locale, and mapping checks
- **Runtime adapters:** run the same HTTP code on FPM, the PHP built-in server, or Swoole
- **Command-line inspection:** `filter:list` inspects discovered filters and marks which ones are enabled in `RequestHandler`

## Installation {#installation}

**Requirements:** PHP 8.3+, `ext-dom`; Swoole is optional and only needed when you want the Swoole runtime.

```bash
composer require switon/http
```

`Switon\Core\InputInterface` is bound to `Switon\Http\RequestInterface` by default.

---

## Quick Start {#quick-start}

Define one controller action, return one array, and let the framework normalize it as JSON.

```php
namespace App\Controller;

use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/users')]
class UserController extends Controller
{
    #[GetMapping('')]
    public function indexAction(): array
    {
        return ['users' => ['Alice', 'Bob']];
    }

    #[GetMapping('{id}')]
    public function showAction(int $id): array
    {
        return ['id' => $id];
    }

    #[GetMapping('search')]
    public function searchAction(string $keyword, int $page = 1): array
    {
        return ['keyword' => $keyword, 'page' => $page];
    }
}
```

This is enough for the first route. Scalar action parameters bind by name, so `int $id`, `string $keyword`, and `int $page` come from route or request input. Use `Switon\Http\RequestInterface` when the action needs raw input, and use `Switon\Http\ResponseInterface` only when status, headers, redirects, files, or streaming matter.

If your app uses a global router prefix such as `/api`, the public URL becomes `/api/users` and `/api/users/{id}`.

---

## Core Concepts {#core-concepts}

### Controller Actions {#controller-actions}

Controller classes own route declarations and action methods own the HTTP entry points.

- `#[RequestMapping]` sets the controller prefix
- `#[GetMapping]`, `#[PostMapping]`, and related attributes map verbs to actions
- action parameters receive route values and bound services

### Request {#request}

`Switon\Http\RequestInterface` is the read boundary for route values, query data, parsed body data, headers, files, and request attributes.

### Typed Input {#typed-input}

Use typed input classes with validation when one action needs a stable request shape.

```php
namespace App\Controller;

use Switon\Http\Attribute\RequestBody;
use Switon\Http\Controller;
use Switon\Routing\Attribute\PostMapping;
use Switon\Validating\Attribute\Length;

#[RequestBody]
class CreateUserInput
{
    #[Length(1, 64)]
    public string $name;
}

class UserController extends Controller
{
    #[PostMapping('/users')]
    public function createAction(CreateUserInput $input): array
    {
        return ['name' => $input->name];
    }
}
```

- `#[RequestBody]` reads parsed body input
- `#[RequestQuery]` reads query input
- `#[RequestData]` reads merged request input

### Response {#response}

`Switon\Http\ResponseInterface` is the write boundary for status, headers, cookies, redirects, files, and body content.
Return arrays for normal JSON output; use `ResponseInterface` when the action needs explicit control.

### Response Finalization {#finalization}

Response finalization is the end of the HTTP flow after routing, validation, invoke, and rendering have finished.

### Lifecycle Events {#lifecycle-events}

Request flow is extension-driven.

- request-stage events cover `adjust`, `begin`, `authenticate`, `route`, `authorize`, `validate`, `ready`, `invoke`, `render`, and `end`. Treat these names as event identifiers and keep them in English when translating.
- response-stage events cover `adjust`, `stringify`, `headers`, `body`, and asset sending before the server writes output. Treat these names as event identifiers and keep them in English when translating.
- listeners are the main place for request-wide concerns such as request id, locale, and logging
- auth and validation events are extension points; the actual behavior comes from listeners such as authz, validation, and throttle

### Runtime Adapters {#runtime-adapters}

`Switon\Http\ServerOptions` selects the listen host, port, adapter type, and adapter-specific settings.
`type: auto` is the default.

---

## Configuration {#configuration}

Configure the main HTTP contract in `switon.yml`.

```yaml
# switon.yml
Switon\Http\ServerOptions:
  type: auto
  host: 0.0.0.0
  port: 9501
```

### Option Discovery {#option-discovery}

Use the current option surface directly:

```bash
bash bin/console config:options 'Switon\Http\ServerOptions'
```

---

## Keep in Mind {#keep-in-mind}

- `Switon\Http\RequestInterface::all()` merges route, POST, and query input.
- `Switon\Http\RequestInterface::get()` treats `null` like missing input.
- `Switon\Http\Response::redirect()` stops the current flow intentionally.
- Keep request-wide concerns in listeners, not in controller properties.

## Next {#next}

- **To bind routes, actions, and typed input:** [Controller](controller.md#controller)
- **To read input and request metadata:** [Request](request.md#request)
- **To shape responses, downloads, and streaming:** [Response](response.md#response)
- **To choose server adapters and runtime settings:** [Server](server.md#server-deployment)
- **To go deeper on typed bodies, query, and merged input:** [Typed input binding](typed-input-binding.md#typed-input-binding)
- **To set and inspect cookies on the response:** [Cookies](cookies.md#cookies)
- **To learn common questions:** [FAQ](faq.md#http-faq)
- **To understand the design trade-offs:** [Design Rationale](design.md#design-rationale)
