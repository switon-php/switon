# Add cross-cutting HTTP behavior {#add-cross-cutting-http-behavior}

Use this guide when one working HTTP path now needs request-wide hooks, action-scoped wrappers, or failure-only response policy without turning HTTP internals into the center of the application too early.

## Choose the right extension point {#extension-point}

Start from the real scope of the behavior, not from the mechanism name.

| Need | Use | Keep in mind |
|---|---|---|
| every request or one request lifecycle stage | lifecycle listener under `Switon\Http\RequestHandlerInterface` → `filters` | `filters` is the config key for lifecycle listeners here, not a chained `$next()` pipeline; use this seam for request IDs, CORS, access logs, slowlogs, and auth identity loading |
| only one action or one group of actions | controller interceptor or action-scoped response attribute | best for `#[RequireJson]`, `#[CacheControl]`, `#[ETag]`, or other pre/post wrapping that should stay visible on the affected action |
| only escaped exceptions should become one HTTP policy | exception handler under `Switon\Http\ExceptionDispatcherInterface` → `handlers` | best for maintenance mode, domain-specific error output, or one shared failure rule; this is failure policy, not normal request orchestration |

One common application shape is:

- **Lifecycle listeners** for request IDs, CORS, access logging, and current-caller loading
- **Action-scoped interceptors or attributes** for one route's guard, wrapper, cache headers, or validators
- **Exception handlers** for typed failure mapping after an exception already escaped normal action flow

## Recommended build order {#build-order}

### 1. Start after one request path is already correct {#build-stable-path}

Do not use HTTP extension points to hide unclear routing, binding, validation, or service-boundary problems.
First prove that one route already reaches one action and returns the right outcome.

### 2. Choose by scope before choosing by mechanism {#build-scope}

Ask one question first: who should feel this behavior?

- **all requests** → lifecycle listener
- **some actions only** → interceptor or action attribute
- **only failure mapping** → exception handler

This one decision usually removes most confusion around request-wide filters versus interceptors.

### 3. Prefer built-in listeners before custom request-wide code {#build-builtins}

The HTTP package already ships with request-wide filters such as `Switon\Http\Filter\RequestIdFilter`, `Switon\Http\Filter\CorsFilter`, `Switon\Http\Filter\AccessLogFilter`, and `Switon\Http\Filter\SlowlogFilter`.
Start by configuring those when they already match the job.

### 4. Keep local behavior close to the action {#build-local-behavior}

If one concern belongs to one endpoint or one controller action family, keep it on the action.
That makes `#[RequireJson]`, `#[CacheControl]`, `#[ETag]`, and similar local behavior visible where the response contract actually changes.

### 5. Move failure-only policy into exception handlers {#build-failure-policy}

If the behavior starts only after an exception escapes, do not force it into a lifecycle listener or action interceptor.
Typed exception handlers keep failure formatting and response policy centralized without reshaping normal request flow.

### 6. Stop when one seam is proven {#build-stop}

Do not turn the first HTTP extension into a full platform design.
After one concern is placed correctly, decide whether the next real problem is authentication, caching, or broader API contract work.

## Minimal wiring {#minimal-wiring}

Use short snippets only to show the seams between request-wide hooks, action-local behavior, and failure policy.

Application-level names like `App\Listener\SessionIdentityListener`, `RequireJson`, and `MaintenanceHandler` below are illustrative. The Switon framework symbols and config roots are the stable part.
If your app applies a global router prefix such as `/api`, keep the controller mappings below prefix-free and confirm the real public URL with `bash bin/console router:prefix`.

### Request-wide lifecycle listeners {#wiring-listeners}

Register listeners under `Switon\Http\RequestHandlerInterface` when the behavior should run for the request lifecycle rather than for one controller action:

```yaml
# switon.yml (project root)
Switon\Http\RequestHandlerInterface:
  filters:
    requestId: Switon\Http\Filter\RequestIdFilter
    cors:
      class: Switon\Http\Filter\CorsFilter
      origin: https://example.com
    auth: App\Listener\SessionIdentityListener
```

Here `filters` is still just the config key name for lifecycle listeners.

This is the right seam for behavior such as:

- ensuring request IDs exist
- handling CORS and preflight responses
- writing access logs or slowlogs
- loading the current caller into `IdentityInterface` before controllers run

Use [Add authentication](add-authentication.md#add-authentication) when identity loading itself becomes the main task.

### Action-scoped interceptors and response attributes {#wiring-action-scope}

Keep action-local behavior on the action when the behavior should stay visible only where that route changes.

A custom interceptor can reject or wrap one action family before the action body runs:

```php
use Attribute;
use ReflectionMethod;
use Switon\Core\Attribute\Autowired;
use Switon\Invocation\Attribute\Interceptor;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;

#[Attribute(Attribute::TARGET_METHOD)]
class RequireJson extends Interceptor
{
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ResponseInterface $response;

    public function preHandle(ReflectionMethod $method): bool
    {
        if (!$this->request->wantsJson()) {
            $this->response->json(['code' => 406, 'msg' => 'JSON only'], 406);
            return false;
        }

        return true;
    }
}
```

`RequireJson` extends `Switon\Invocation\Attribute\Interceptor`. When an interceptor needs HTTP context, inject `Switon\Http\RequestInterface` and `Switon\Http\ResponseInterface` with `#[Autowired]` on the interceptor itself.

Use built-in action attributes when response behavior is local to one endpoint:

```php
namespace App\Controller;

use App\Service\TaskServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\CacheControl;
use Switon\Http\Attribute\ETag;
use Switon\Http\ResponseInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('')]
class TaskController
{
    #[Autowired] protected TaskServiceInterface $taskService;

    #[CacheControl(maxAge: 120, public: true)]
    #[ETag('updated_at')]
    #[GetMapping('/tasks')]
    public function listAction(ResponseInterface $response): void
    {
        $response->json([
            'items' => $this->taskService->listVisibleTasks(),
            'updated_at' => $this->taskService->latestUpdateAt(),
        ]);
    }
}
```

Keep this seam for behavior such as:

- JSON-only or header-based guards on one action
- one endpoint's cache validators or cache headers
- local pre/post wrapping around a specific controller action

If the app adds a global prefix such as `/api`, the public route becomes `/api/tasks` even though the controller still maps `'/tasks'`.

### Exception handlers for failure-only policy {#wiring-exception-handlers}

Register exception handlers under `Switon\Http\ExceptionDispatcherInterface` when the concern belongs to escaped exceptions rather than normal request flow:

```yaml
# switon.yml (project root)
Switon\Http\ExceptionDispatcherInterface:
  handlers:
    App\Exception\MaintenanceException: App\ExceptionHandler\MaintenanceHandler
```

This block lives in the root `switon.yml` file under the service id `Switon\Http\ExceptionDispatcherInterface`. The `handlers` map is "exception class" -> "handler class".

```php
namespace App\ExceptionHandler;

use Switon\Core\Attribute\Autowired;
use Switon\Http\ExceptionHandlerInterface;
use Switon\Http\ResponseInterface;

class MaintenanceHandler implements ExceptionHandlerInterface
{
    #[Autowired] protected ResponseInterface $response;

    public function handle(\Throwable $exception): bool
    {
        if (!$exception instanceof MaintenanceException) {
            return false;
        }

        $this->response->json(['code' => 503, 'msg' => 'Service under maintenance'], 503);
        return true;
    }
}
```

`Switon\Http\ExceptionHandlerInterface` is the small HTTP failure hook for this seam. It has one method, `handle(\Throwable $exception): bool`: return `true` when this handler already decided the HTTP outcome, or `false` when the dispatcher should continue to fallback handling or another handler.

Use this seam when the real need is one shared failure outcome, not another normal request hook.

## What to avoid first {#pitfalls}

Keep the first HTTP extension path intentionally narrow.

Avoid these too early:

- **Putting action-only behavior in a global listener** — if one route alone needs the behavior, keep it local
- **Parsing headers, cookies, or auth state inside many controllers** — request-wide concerns should have one lifecycle seam
- **Using interceptors for failure policy that belongs to exception handlers** — escaped exceptions already have a dedicated HTTP mapping seam
- **Treating hidden current-user output as safe page-cache input** — session or token variance must stay explicit before broader response caching
- **Growing the first extension into a full auth, tracing, or cache platform** — first prove one placement boundary clearly

A beginner should feel this after finishing the first extension path:

> I know which HTTP seam owns this behavior and why it belongs there.

## Go deeper {#go-deeper}

- **To build the first JSON route and controller path before extending it:** [Build an API](build-an-api.md#build-an-api)
- **To build the first controller-to-template page path before extending it:** [Build HTML pages](build-html-pages.md#build-html-pages)
- **To choose typed input binding, controllers, and normal request handling details:** [HTTP](https://github.com/switon-php/http/blob/main/docs/README.md) · [Controller](https://github.com/switon-php/http/blob/main/docs/controller.md#controller)
- **To trace lifecycle order, listener registration, and exception flow:** [Understand the request lifecycle](../concepts/request-lifecycle.md#request-lifecycle)
- **To keep auth identity loading request-wide and protect endpoints next:** [Add authentication](add-authentication.md#add-authentication) · [Session](https://github.com/switon-php/session/blob/main/docs/README.md) · [JWT](https://github.com/switon-php/jwt/blob/main/docs/README.md) · [Authz](https://github.com/switon-php/authz/blob/main/docs/README.md)
- **To add route-level abuse protection and own the `429` response surface deliberately:** [Protect endpoints with rate limits](protect-endpoints-with-rate-limits.md#protect-endpoints-with-rate-limits)
- **To keep response-local cache validators and output behavior on one action:** [Response](https://github.com/switon-php/http/blob/main/docs/response.md#response)
- **To choose the first safe cache layer once repeated reads become the real problem:** [Add caching](add-caching.md#add-caching) · [HTTP Cache](https://github.com/switon-php/http-cache/blob/main/docs/README.md)
- **To publish the contract after routes, auth, and response behavior are stable:** [Add API docs](add-api-docs.md#add-api-docs)
