# Authorize (access control)

Use when the user asks to add, change, or verify **authorization** on controllers, actions, or other HTTP endpoints
using `#[Authorize]`.

## 1. Where to use

- **Controllers (HTTP):** Class-level or method-level `#[Authorize]` on controller classes and actions (
  see [references/controller.md](references/controller.md) for controller structure and routing attributes).
- **Views:** View actions are still controller actions; apply `#[Authorize]` on the controller class or on specific view
  actions.
- **Other entrypoints:** For non-HTTP entrypoints (e.g. CLI commands), prefer explicit checks in the command body or
  dedicated filter instead of `#[Authorize]`, unless the app already uses a common pattern.

## 2. Basic rules

- **Attribute:** Use `Switon\Authorizing\Attribute\Authorize` (import as `Authorize` in the controller).
- **Roles / policies:**
    - `Authorize::AUTHENTICATED`, `Authorize::SUPERUSER`, `Authorize::ANONYMOUS` for common roles.
    - Custom roles or policies are allowed; follow the app’s existing usage pattern when adding new ones.
- **Class vs method:**
    - Class-level `#[Authorize(...)]` → applies to **all** actions in that controller.
    - Method-level `#[Authorize(...)]` → **overrides** class-level for that action.

## 3. When changing authorization

- **Tighten access:** Prefer adding or narrowing `#[Authorize]` on specific actions rather than on the whole class,
  unless all actions share the same access rule.
- **Loosen access:** Remove or relax method-level `#[Authorize]` only when the requirement clearly changed; document the
  reason in commit/message rather than comments in code.
- **Keep behavior consistent:** When copying or moving actions between controllers, copy their `#[Authorize]` attributes
  or equivalent checks.

## 4. Verify authorization

When the user asks to verify access control for an endpoint:

1. **router:resolve** to find handler and controller/action (see [references/router.md](references/router.md)).
2. Check the controller class for class-level `#[Authorize]` and each public action for method-level `#[Authorize]`.
3. Report the **effective rule** per action (method-level if present, else class-level, else “no explicit Authorize
   attribute”) and whether it matches the described requirement.

## 5. Source of truth

- `Switon\Authorizing\Attribute\Authorize` (attribute PHPDoc and implementation).
- App controllers and existing usage patterns (see [references/controller.md](references/controller.md)).
- For role/permission modeling and permission_code checks (e.g. menus), see [references/rbac.md](references/rbac.md).
