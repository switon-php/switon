# Add authorization {#add-authorization}

Use this guide when authentication already works and one working HTTP or service path now needs explicit access rules, ownership checks, or business permissions without turning RBAC product design, admin UIs, or external policy engines into the first answer.

## Choose the first authorization boundary {#choose-the-first-authorization-boundary}

Start with the smallest boundary that matches the real decision.

| Boundary | Best when | Keep in mind |
|---|---|---|
| `#[Authorize(...)]` on one action | one endpoint should be public, authenticated-only, or limited to one obvious business role | best first move when the rule belongs close to the handler and should stay visible in code review |
| class-level `#[Authorize(...)]` with method overrides | one controller family shares the same default policy with a few exceptions | method-level attributes override the class default |
| `AuthorizationInterface::can()` / `authorize()` | service code, workers, commands, or domain orchestration must enforce or branch on permissions | pass stable handler-id codes such as `admin::refund-order` when you already know them |
| `LookupInterface` mapping | business roles should grant exact permission codes instead of only matching controller attributes directly | lookup matching is exact; CSV codes stay comma-separated with no spaces |
| `VoterInterface` | access depends on ownership, tenant, IP, or another contextual rule that roles alone cannot express | first non-null voter result wins and runs before built-in role behavior |

One common application shape is:

- `#[Authorize(Authorize::AUTHENTICATED)]` for “logged-in only” paths
- one business role such as `admin` on visibly privileged handlers
- `LookupInterface` once service checks need exact permission codes
- one voter only when context such as ownership or tenant membership becomes the real rule

## Recommended build order {#recommended-build-order}

### 1. Start after identity loading is already real {#build-order-auth-first}

Authorization gets much easier after one request path already sets `IdentityInterface` reliably.
Do not use authz docs to debug missing session, token, or current-user wiring first.

### 2. Protect one obvious handler with `#[Authorize]` {#build-order-attribute-first}

Start with the endpoint you can explain in one sentence:

- login or signup should stay `ANONYMOUS`
- current-user or account pages should be `AUTHENTICATED`
- visibly privileged admin endpoints may use one business role such as `'admin'`

That first rule proves the handler boundary before you expand into broader permission catalogs.
If the app applies a global router prefix such as `/api`, keep the controller mappings prefix-free and confirm the real public URL with `bash bin/console router:prefix` before you debug `401` or `403` responses.

### 3. Keep identity-state markers separate from business roles {#build-order-markers}

Use `Authorize::ANONYMOUS` and `Authorize::AUTHENTICATED` only as identity-state markers.
Use custom strings such as `'admin'` or `'editor'` for business access.

This keeps “who is logged in?” separate from “what may they do?”.

### 4. Move service-owned decisions to `AuthorizationInterface` {#build-order-service-checks}

When the real decision happens inside a service, do not hide it behind controller-only attributes.
Inject `AuthorizationInterface` and:

- use `can()` when denial is an ordinary branch in business logic
- use `authorize()` when denial should become the HTTP outcome and emit authz events

Prefer stable handler-id codes such as `admin::refund-order` or `rbac.role::index` when you already know them.
Pass a real `FQCN::method` only when you intentionally want handler metadata resolution.

### 5. Add `LookupInterface` only when direct roles stop scaling {#build-order-lookup}

Direct custom roles on handlers are enough for the first few endpoints.
Add `LookupInterface` when one business role should grant more than one exact permission code across handlers or service checks.

Keep codes exact and explicit:

- `admin::refund-order`
- `catalog.product::publish`
- `rbac.role::edit`

Do not depend on wildcard auto-expansion in runtime checks.

### 6. Add a voter only for real context rules {#build-order-voter}

A voter is the right tool when access depends on more than roles and permission codes.
Typical examples:

- resource ownership
- tenant isolation
- IP allowlists
- environment or network restrictions

Keep voters narrow.
Let them answer the contextual question, not replace your whole permission model.

### 7. Stop before RBAC platform design {#build-order-stop}

The first authorization win is usually small:

- a few clear handler attributes
- a few stable permission codes
- maybe one contextual voter

You do **not** need permission tables, admin UIs, import/export flows, or policy DSLs before the first app path feels correct.

## Minimal wiring {#minimal-wiring}

Use short code only to show the seams between handlers, service checks, lookup mappings, and contextual voters.
Application names below are illustrative. The stable part is the Switon authorization surface.

### Protect handlers with visible rules {#minimal-wiring-handler-rules}

Start with controller-level rules that stay easy to read:

```php
namespace App\Controller;

use Switon\Authorizing\Attribute\Authorize;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[Authorize(Authorize::AUTHENTICATED)]
#[RequestMapping('')]
class AdminOrderController
{
    #[GetMapping('/admin/orders')]
    public function indexAction(): array
    {
        return ['ok' => true];
    }

    #[PostMapping('/admin/orders/{id}/refund')]
    #[Authorize('admin')]
    public function refundAction(int $id): array
    {
        return ['refunded' => $id];
    }
}
```

This is the best first move when method-level exceptions should override a controller-wide default.
If the app adds a global prefix such as `/api`, the public paths become `/api/admin/orders` and `/api/admin/orders/{id}/refund` even though the controller mappings stay prefix-free.

### Put service-owned permission checks in `AuthorizationInterface` {#minimal-wiring-service-checks}

Use programmatic checks when the decision belongs to business code:

```php
namespace App\Service;

use Switon\Authorizing\AuthorizationInterface;
use Switon\Core\Attribute\Autowired;

class RefundOrderService
{
    #[Autowired] protected AuthorizationInterface $authorization;

    public function refund(int $orderId): void
    {
        $this->authorization->authorize('admin::refund-order');

        // ... refund logic ...
    }

    public function canViewAuditLog(): bool
    {
        return $this->authorization->can('admin::audit-log');
    }
}
```

Use `authorize()` at request boundaries or boundary-like service entrypoints.
Use `can()` when the result should stay a normal application branch.

### Map business roles to exact permission codes {#minimal-wiring-lookup}

Add lookup mapping when one role should grant more than one explicit permission:

```yaml
# switon.yml
Switon\Authorizing\LookupInterface:
  permissions:
    admin: admin::refund-order,admin::audit-log
    editor: catalog.article::publish,catalog.article::archive
```

Keep the CSV exact and compact.
Do not include spaces between permission codes.

### Add one contextual voter for ownership or tenant rules {#minimal-wiring-voter}

Use a voter when roles alone are not enough:

```yaml
# switon.yml
Switon\Authorizing\AuthorizationInterface:
  voters:
    - App\Auth\OwnershipVoter
```

```php
namespace App\Auth;

use ReflectionMethod;
use Switon\Authorizing\VoterInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Principal\IdentityInterface;
use Switon\Http\RequestInterface;

class OwnershipVoter implements VoterInterface
{
    #[Autowired] protected IdentityInterface $identity;
    #[Autowired] protected RequestInterface $request;

    public function vote(string $permission, ?ReflectionMethod $handler): ?bool
    {
        if ($permission !== 'blog.post::edit') {
            return null;
        }

        return (string) $this->request->get('author_id') === (string) $this->identity->getId();
    }
}
```

This keeps the ownership rule explicit and contextual.
If the voter does not apply, return `null` so the default authz flow continues.

### Use `PermissionsInterface` for tooling, not runtime grants {#minimal-wiring-catalog}

If you later need catalog or RBAC UI support, treat `PermissionsInterface` as a tooling surface:

```php
namespace App\Command;

use Switon\Authorizing\PermissionsInterface;
use Switon\Core\Attribute\Autowired;

class DumpPermissionCatalogCommand
{
    #[Autowired] protected PermissionsInterface $permissions;

    public function defaultAction(): array
    {
        return $this->permissions->flatten($this->permissions->scanCatalog());
    }
}
```

That catalog reflects routed controller `#[Authorize]` metadata only.
It is useful for exports, diagnostics, and admin tooling, but it is not the merged runtime truth when lookup providers or voters are involved.

## What to avoid first {#what-to-avoid-first}

Keep the first authorization path intentionally narrow.

Avoid these too early:

- **Treating authz as authentication** — `Authz` consumes `IdentityInterface`; it does not create login state by itself
- **Using empty `#[Authorize]` as if it meant public access** — it does not grant guest access; use `Authorize::ANONYMOUS` explicitly
- **Mixing `AUTHENTICATED` or `ANONYMOUS` with custom roles** — identity-state markers must stay standalone in `#[Authorize]`
- **Assuming runtime wildcard expansion** — lookup and service checks match exact strings, not `controller::*` patterns expanded on the fly
- **Adding spaces in `LookupInterface` CSV config** — runtime matching is exact and whitespace is significant
- **Burying service-owned permission decisions inside controllers** — when the real boundary is in business logic, use `AuthorizationInterface`
- **Using `PermissionsInterface::scanCatalog()` as the runtime source of truth** — it does not include external lookup sources or voter outcomes
- **Jumping straight to full RBAC product design** — prove one clear app-owned permission path first

A beginner should feel this after finishing the first authorization path:

> I know which rules belong on handlers, which belong in services, when lookup mapping becomes worth it, and when a voter is the right escape hatch.

## Go deeper {#go-deeper}

- **To finish identity loading before policy design:** [Add authentication](add-authentication.md#add-authentication)
- **To stay on controller-level or action-level authorization rules:** [Authz](https://github.com/switon-php/authz/blob/main/docs/README.md) · [Controller](https://github.com/switon-php/http/blob/main/docs/controller.md#controller)
- **To keep business permissions explicit in service code:** [Authz programmatic checks](https://github.com/switon-php/authz/blob/main/docs/README.md#service-checks)
- **To add config-backed or database-backed permission lookup:** [Authz permission lookup](https://github.com/switon-php/authz/blob/main/docs/README.md#permission-lookup)
- **To add contextual ownership, tenant, or IP decisions:** [Authz voters](https://github.com/switon-php/authz/blob/main/docs/README.md#voters)
- **To build a permission catalog or RBAC UI view:** [Authz FAQ](https://github.com/switon-php/authz/blob/main/docs/faq.md#authorization-faq)
- **To test protected handlers and `401` / `403` boundaries:** [Add testing to a working app](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
- **To document protected endpoints and denial behavior for clients:** [Add API docs](add-api-docs.md#add-api-docs)
- **To protect auth-adjacent endpoints from abuse separately from authz policy:** [Protect endpoints with rate limits](protect-endpoints-with-rate-limits.md#protect-endpoints-with-rate-limits)
