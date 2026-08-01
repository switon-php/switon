# Add authentication {#add-authentication}

Use this guide when one working HTTP path now needs login state, bearer tokens, or endpoint protection without turning identity-platform design into the center of the application too early.

## Choose the first authentication shape {#authentication-shape}

Start with the transport shape that matches the real caller boundary.

| Shape | Best when | Keep in mind |
|---|---|---|
| [Session](https://github.com/switon-php/session/blob/main/docs/README.md) | browser-first login/logout/current-user flows should keep state on the server and move only a session cookie through the client | best default for server-rendered or same-origin app flows; cookie scope and deferred session writes matter |
| [JWT](https://github.com/switon-php/jwt/blob/main/docs/README.md) | API clients, mobile apps, or service callers should send a bearer token on every request | `decode()` is the trust boundary; keep claims compact and explicit |
| [Authz](https://github.com/switon-php/authz/blob/main/docs/README.md) | the caller identity already exists and endpoints now need access rules | authz does **not** authenticate by itself; it consumes `IdentityInterface` after your auth layer populates it |

Common shape: **Session** for browser flows, **JWT** for bearer-token clients, **Authz** after either path once `IdentityInterface` is populated.

## Recommended build order {#build-order}

1. Start only after one HTTP path already works.
2. Choose Session or JWT by caller transport.
3. Load `IdentityInterface` in one request-wide listener.
4. Keep login state small and explicit.
5. Add `#[Authorize]` only after identity works.
6. Revisit caching only after identity variance is explicit.

## Minimal wiring {#minimal-wiring}

Use short code only to show the seams between HTTP, identity loading, and authorization.

Application-level names like `UserRepository`, `LoginService`, and `AuthController` below are illustrative. The Switon framework symbols and config surfaces are the stable part.

### Shared request-wide auth hook {#wiring-request-hook}

Register one auth listener in the HTTP lifecycle:

```yaml
# switon.yml (project root)
Switon\Http\RequestHandlerInterface:
  filters:
    auth: App\Listener\SessionIdentityListener
```

Swap that class to a JWT-based listener when the caller boundary is token-first.
This wiring is the hidden prerequisite: `#[Authorize]` protects endpoints, but this request-wide listener is what loads identity before controller code runs.
Request-wide auth belongs in HTTP listener wiring, not repeated controller code.

### Session-first browser flow {#wiring-session}

Use [Session](https://github.com/switon-php/session/blob/main/docs/README.md) when login state should stay server-side and travel by cookie:

```php
namespace App\Listener;

use App\Repository\UserRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Eventing\Attribute\EventListener;
use Switon\Principal\IdentityInterface;
use Switon\Http\Event\RequestBegin;
use Switon\Session\SessionInterface;

class SessionIdentityListener
{
    #[Autowired] protected SessionInterface $session;
    #[Autowired] protected IdentityInterface $identity;
    #[Autowired] protected UserRepository $userRepository;

    #[EventListener]
    public function onBegin(RequestBegin $event): void
    {
        $userId = $this->session->get('user_id');

        if ($userId === null) {
            $this->identity->clear();
            return;
        }

        $user = $this->userRepository->find((int) $userId);

        if ($user === null) {
            $this->session->destroy();
            $this->identity->clear();
            return;
        }

        $this->identity->set($user->id, $user->email, $user->roles);
    }
}
```

`IdentityInterface::set()` is `set(id, name, roles)`:

- first argument = stable subject id for `getId()`
- second argument = display name or principal name for `getName()`
- third argument = normalized role list for `getRoles()`

```php
namespace App\Controller;

use App\Input\LoginInput;
use App\Service\LoginServiceInterface;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Principal\IdentityInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Session\SessionInterface;

#[RequestMapping('/auth')]
class AuthController
{
    #[Autowired] protected LoginServiceInterface $loginService;
    #[Autowired] protected SessionInterface $session;

    #[PostMapping('/login')]
    #[Authorize(Authorize::ANONYMOUS)]
    public function loginAction(LoginInput $input): array
    {
        $user = $this->loginService->verify($input->email, $input->password);
        $this->session->set('user_id', $user->id);

        return ['ok' => true];
    }

    #[GetMapping('/me')]
    #[Authorize(Authorize::AUTHENTICATED)]
    public function meAction(IdentityInterface $identity): array
    {
        return [
            'id' => $identity->getId(),
            'name' => $identity->getName(),
            'roles' => $identity->getRoles(),
        ];
    }

    #[PostMapping('/logout')]
    #[Authorize(Authorize::AUTHENTICATED)]
    public function logoutAction(): array
    {
        $this->session->destroy();

        return ['ok' => true];
    }
}
```

Keep the stored marker small; re-load the current user through your own repository/service boundary.
Keep the login input named as an input object too, so raw `RequestInterface` and bound credential input stay visibly separate.

### JWT-first API flow {#wiring-jwt}

Use [JWT](https://github.com/switon-php/jwt/blob/main/docs/README.md) when the caller should send a bearer token on every request:

```yaml
# switon.yml (project root)
Switon\Jwt\JwtInterface:
  alg: HS256
  key: ${JWT_KEY}

Switon\Http\RequestHandlerInterface:
  filters:
    auth: App\Listener\JwtIdentityListener
```

The same rule applies here: controller attributes check access, but the request-wide listener is what turns the bearer token into `IdentityInterface`.

```php
namespace App\Listener;

use Switon\Core\Attribute\Autowired;
use Switon\Eventing\Attribute\EventListener;
use Switon\Principal\IdentityInterface;
use Switon\Http\Event\RequestBegin;
use Switon\Http\RequestInterface;
use Switon\Jwt\JwtInterface;

class JwtIdentityListener
{
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected JwtInterface $jwt;
    #[Autowired] protected IdentityInterface $identity;

    #[EventListener]
    public function onBegin(RequestBegin $event): void
    {
        $header = (string) $this->request->header('authorization', '');

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $this->identity->clear();
            return;
        }

        try {
            $claims = $this->jwt->decode($matches[1]);
            $this->identity->set(
                (string) ($claims['sub'] ?? ''),
                (string) ($claims['name'] ?? $claims['sub'] ?? ''),
                (array) ($claims['roles'] ?? []),
            );
        } catch (\Throwable) {
            $this->identity->clear();
        }
    }
}
```

```php
namespace App\Controller;

use App\Input\LoginInput;
use App\Service\LoginServiceInterface;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Principal\IdentityInterface;
use Switon\Jwt\JwtInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/auth')]
class ApiAuthController
{
    #[Autowired] protected LoginServiceInterface $loginService;
    #[Autowired] protected JwtInterface $jwt;

    #[PostMapping('/login')]
    #[Authorize(Authorize::ANONYMOUS)]
    public function loginAction(LoginInput $input): array
    {
        $user = $this->loginService->verify($input->email, $input->password);

        return [
            'token' => $this->jwt->encode([
                'sub' => (string) $user->id,
                'name' => $user->email,
                'roles' => $user->roles,
            ], 3600),
        ];
    }

    #[GetMapping('/me')]
    #[Authorize(Authorize::AUTHENTICATED)]
    public function meAction(IdentityInterface $identity): array
    {
        return [
            'id' => $identity->getId(),
            'name' => $identity->getName(),
            'roles' => $identity->getRoles(),
        ];
    }
}
```

Catch exact JWT exceptions in application code only when different failure outcomes really matter.
If your app applies a global router prefix such as `/api`, the public paths become `/api/login` and `/api/me` even though the controller mappings stay prefix-free.

### Authorization after identity exists {#wiring-authz}

Once identity loading works, protect handlers with [Authz](https://github.com/switon-php/authz/blob/main/docs/README.md):

```yaml
# switon.yml (project root)
Switon\Authorizing\LookupInterface:
  permissions:
    admin: admin::audit-log
```

```php
namespace App\Controller;

use Switon\Authorizing\Attribute\Authorize;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/admin')]
class AdminController
{
    #[GetMapping('audit-log')]
    #[Authorize('admin')]
    public function auditLogAction(): array
    {
        return ['ok' => true];
    }
}
```

Keep the first rule small:

- `ANONYMOUS` for login/guest endpoints
- `AUTHENTICATED` for current-user or logged-in-only endpoints
- one business role such as `admin` only after the identity source and role shape are already clear

## What to avoid first {#pitfalls}

- **Treating `Authz` as the login system** — it enforces access after identity exists; it does not create identity by itself
- **Storing full entities or secrets in session/JWT** — prefer compact ids, names, and normalized role lists
- **Caching hidden current-user output with `HTTP Cache`** — session, token, or cookie-only variance is not a safe page-cache key by default
- **Using direct `read()` / `write()` session APIs for normal request auth** — stay on `get()` / `set()` for ordinary request code
- **Mixing `AUTHENTICATED` or `ANONYMOUS` with custom roles in one attribute** — keep identity-state markers standalone
- **Expanding the first auth path into MFA, federation, or full RBAC tooling** — prove one clear login/current-user/protected-endpoint boundary first

## Go deeper {#go-deeper}

- **To connect this after the first request path:** [Build an API](build-an-api.md#build-an-api) · [HTTP](https://github.com/switon-php/http/blob/main/docs/README.md)
- **To connect this after stored user or role data is stable:** [Persist data](persist-data.md#persist-data)
- **To stay on cookie-backed login state:** [Session](https://github.com/switon-php/session/blob/main/docs/README.md) · [HTTP cookies](https://github.com/switon-php/http/blob/main/docs/cookies.md#cookies)
- **To stay on bearer-token validation:** [JWT](https://github.com/switon-php/jwt/blob/main/docs/README.md)
- **To send welcome, recovery, or account-notice mail from auth flows:** [Send transactional email](send-transactional-email.md#send-transactional-email)
- **To protect login or public auth-adjacent endpoints against abuse:** [Protect endpoints with rate limits](protect-endpoints-with-rate-limits.md#protect-endpoints-with-rate-limits)
- **To design roles, permissions, ownership checks, and service-level policy next:** [Add authorization](add-authorization.md#add-authorization)
- **To protect login, current-user, and protected-endpoint behavior before publishing the contract:** [Add testing to a working app](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
- **To publish login, current-user, and protected endpoint contracts for clients:** [Add API docs](add-api-docs.md#add-api-docs) · [OpenAPI](https://github.com/switon-php/openapi/blob/main/docs/README.md)
- **To protect endpoints and service checks:** [Authz](https://github.com/switon-php/authz/blob/main/docs/README.md)
- **To place request-wide auth hooks correctly:** [HTTP](https://github.com/switon-php/http/blob/main/docs/README.md) · [Understand the request lifecycle](../concepts/request-lifecycle.md#request-lifecycle)
- **To revisit caching after identity variance is explicit:** [Add caching](add-caching.md#add-caching) · [HTTP Cache](https://github.com/switon-php/http-cache/blob/main/docs/README.md)
