# Protect endpoints with rate limits {#protect-endpoints-with-rate-limits}

Use this guide when one working login path, public API route, or abuse-prone action now needs shared request limits without turning custom filter, quota platforms, or billing tiers into the first answer.

## Choose the first rate-limit boundary {#limit-boundary}

Start with the abuse pattern that is actually hurting the app.

| Boundary | Best when | Keep in mind |
|---|---|---|
| `#[RateLimit(...)]` on one action | one endpoint such as login, password reset, or search is the clear hot spot | best first move when only a few routes are risky |
| `#[RateLimit(...)]` on one controller | one whole controller family shares the same public-read or write pressure | method-level attributes still override class-level defaults |
| config fallback `limits` | you want a safety net for unannotated actions while the app is still small | config applies only when no class or method attribute exists |
| `RateLimiterInterface::hit()` | the real key is business-owned, such as phone number, tenant, API key, or SMS target | manual keys are used as-is; config `prefix` does not apply |

One common application shape is:

- **login or reset endpoints** with tight action-level limits
- **public read endpoints** with broader controller-level or action-level limits
- **authenticated endpoints** that automatically key per current identity after `Add authentication` is in place
- **manual `hit()` calls** only when HTTP route identity is not the real abuse boundary

## Recommended build order {#build-order}

### 1. Start from one endpoint users or attackers can actually hit {#build-hot-path}

Pick a real abuse surface first:

- login
- password reset request
- public search
- anonymous create/submit endpoint

Do not start with a global quota theory before one risky path is clear.

### 2. Protect login first, then widen to public reads {#build-login}

Login and recovery endpoints usually deserve the first tight limit because brute-force pressure is clearer there than on most business routes.
After that, protect anonymous reads or hot write actions that can create cost, noise, or spam.
If your app applies a global router prefix such as `/api`, keep the controller mappings prefix-free and confirm the real public URL with `bash bin/console router:prefix` before you test the protected endpoint.

### 3. Let identity shape follow the real caller boundary {#build-identity}

Switon keys automatic request limits by:

- controller
- action
- identity
- rule window

For guests, identity is the client IP.
For authenticated callers, identity is `IdentityInterface::getName()`.

That means the same route naturally separates guest traffic from authenticated traffic once auth is real.

### 4. Keep the first rules small and readable {#build-rules}

Start with one or two windows you can explain in one sentence.
Examples:

- `5/m` for login attempts
- `60/m` for a public listing endpoint
- `['10/m', '100/h']` when you need both short-window and longer-window protection

Add `burst` only when short spikes are legitimate and you understand that it affects the **first** rule only.

### 5. Decide whether the default `429` is enough {#build-429}

The package already raises `RateLimitExceededException`. In normal HTTP exception handling, that renders as a `429` response.
Keep that default first when clients only need a stable denial.
Add a custom exception handler only when callers truly need extra headers or app-specific error wording.

### 6. Move to `hit()` only when route identity is not enough {#build-hit}

If the real abuse key is phone number, tenant, or upstream API key, use `RateLimiterInterface::hit()` in your own service boundary.
Do not force those business keys into route attributes when the route itself is not the real quota unit.

### 7. Stop before quota-platform design {#build-stop}

The first rate-limit win does **not** require billing tiers, bot scoring, or analytics dashboards.
Make one login path and one public path trustworthy before you widen the system.

## Minimal wiring {#minimal-wiring}

Use short snippets only to show the seams between endpoint risk, limiter placement, and HTTP response policy.

Application-level names like `AuthController`, `PublicApiController`, and `TooManyRequestsHandler` below are illustrative. The stable part is the Switon throttle attribute, config surface, and optional exception-handler seam.

### Keep Redis and throttle config explicit {#wiring-config}

Start with the minimal limiter dependencies in `switon.yml`:

```yaml
# switon.yml
Switon\Redis\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: redis://127.0.0.1:6379/0

Switon\Throttle\RateLimiterInterface:
  enabled: true
  limits: ['60/m']
```

Keep fallback `limits` only as a safety net.
Use action or controller attributes for the endpoints that truly deserve deliberate limits.

### Put tight limits on login and other hot actions {#wiring-action}

Use method-level `#[RateLimit]` when one action is clearly riskier than the rest:

```php
namespace App\Controller;

use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Throttle\Attribute\RateLimit;

#[RateLimit('60/m')]
#[RequestMapping('')]
final class PublicApiController
{
    #[PostMapping('/login')]
    #[RateLimit(['5/m', '30/h'])]
    public function loginAction(): array
    {
        return ['ok' => true];
    }

    #[GetMapping('/search')]
    public function searchAction(): array
    {
        return ['items' => []];
    }
}
```

This keeps the common pattern readable:

- one controller-level default for general traffic
- one tighter method-level rule for login or another abuse-prone action

If the app adds a global prefix such as `/api`, the public routes become `/api/login` and `/api/search` even though the controller mappings stay `'/login'` and `'/search'`.

### Let authentication improve the key shape automatically {#wiring-auth}

Once [Add authentication](add-authentication.md#add-authentication) is in place, authenticated requests automatically key by `IdentityInterface::getName()` instead of IP.
That means a protected route can keep the same `#[RateLimit]` attribute while its effective caller identity becomes more specific.

Use the auth guide when the identity-loading path itself is still unfinished.

### Use direct `hit()` only for business-owned limiter keys {#wiring-hit}

When the quota belongs to a phone number, tenant, or some other domain key, throttle inside the service boundary:

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Throttle\RateLimiterInterface;

final class SmsService
{
    #[Autowired] protected RateLimiterInterface $rateLimiter;

    public function send(int $userId, string $phone): void
    {
        $this->rateLimiter->hit('sms:' . $phone, '5/m');
        $this->rateLimiter->hit('sms:user:' . $userId, ['10/m', '100/h']);
    }
}
```

This is the right seam when route identity is not the real abuse boundary.
Remember that manual keys are used exactly as you pass them.

### Add a custom 429 response only when clients need it {#wiring-handler}

If you need `Retry-After` or a more explicit body, map the exception through the normal HTTP exception-handler seam:

```yaml
# switon.yml
Switon\Http\ExceptionDispatcherInterface:
  handlers:
    Switon\Throttle\Exception\RateLimitExceededException: App\ExceptionHandler\TooManyRequestsHandler
```

```php
namespace App\ExceptionHandler;

use Switon\Core\Attribute\Autowired;
use Switon\Http\ExceptionHandlerInterface;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Throttle\Exception\RateLimitExceededException;
use Throwable;

final class TooManyRequestsHandler implements ExceptionHandlerInterface
{
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ResponseInterface $response;

    public function handle(Throwable $exception): bool
    {
        if (!$exception instanceof RateLimitExceededException) {
            return false;
        }

        $this->response->setHeader('Retry-After', '60');

        if ($this->request->wantsJson()) {
            $this->response->json(['code' => 429, 'msg' => 'Too Many Requests'], 429);
        } else {
            $this->response->text('Too Many Requests', 'text/plain', 429);
        }

        return true;
    }
}
```

Keep this handler only when client behavior truly depends on a more explicit 429 surface.
The package itself already gives you the basic `429` outcome.

## What to avoid first {#pitfalls}

Keep the first rate-limit path intentionally narrow.

Avoid these too early:

- **Protecting everything before you know which routes are hot** — start from login, reset, or obvious public hotspots first
- **Treating class-level limits like they can be silently disabled per method** — a method without its own attribute still inherits the class-level rule
- **Assuming `burst` applies to every rule in a list** — it affects only the first rule
- **Expecting config defaults to override explicit attributes** — method and class attributes win; fallback config applies only when no attribute exists
- **Using route attributes when the real quota key is business-owned** — move to `hit()` for phone, tenant, or API-key-shaped limits
- **Expecting rate-limit headers to appear automatically** — add them yourself in the exception handler or response pipeline when needed

A beginner should feel this after finishing the first rate-limit path:

> I know which endpoints to protect first, how Switon keys callers, and when the default 429 is enough versus when I should own the response surface.

## Go deeper {#go-deeper}

- **To connect this after the first API or page path:** [Build an API](build-an-api.md#build-an-api) · [Build HTML pages](build-html-pages.md#build-html-pages) · [HTTP](../../packages/http/docs/README.md)
- **To settle caller identity before keyed authenticated limits matter:** [Add authentication](add-authentication.md#add-authentication)
- **To place custom 429 failure policy or response hooks on the right HTTP seam:** [Add cross-cutting HTTP behavior](add-cross-cutting-http-behavior.md#add-cross-cutting-http-behavior)
- **To go deeper on attributes, fallback config, burst, and key composition:** [Throttle](../../packages/throttle/docs/README.md)
- **To tune Redis topology and shared counters:** [Redis](../../packages/redis/docs/README.md)
- **To document 429 behavior once public and protected routes are stable:** [Add API docs](add-api-docs.md#add-api-docs)
- **To protect the first abuse-prone paths before you widen them further:** [Add testing to a working app](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
