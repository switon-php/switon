# RBAC & permissions (roles / permission_code)

Use when the user asks to add, change, or verify **roles, permissions, or permission codes** (e.g. menu visibility,
admin roles) in the app.

## 1. Concepts and placement

- **Roles:** Typically entities in an RBAC module (e.g. `Role`, `AdminRole` in an `Rbac` area or package). Roles
  describe **who** (admin, editor, viewer…).
- **Permissions:** Permission codes (strings) that describe **what** an identity is allowed to do (e.g.
  `system.env.view`, `admin.user.lock`). Often stored on permission/route/menu records.
- **Authorization service:** Use `Switon\Authorizing\AuthorizationInterface` in app code to check permissions (e.g. in
  widgets or controllers) via `can($operation)`.

## 2. Common app patterns

- **Admin menu (widgets):**
    - Widget (e.g. `SideMenuWidget`) loads menu groups/items including a `permission_code` field on each item.
    - For each item, call `AuthorizationInterface::can($permissionCode)`:
        - If `permission_code` is empty → usually treated as public within the admin UI.
        - If not empty and the check returns false → hide that item.
    - After filtering, drop empty groups and return only groups that still have visible items.
- **Controllers / endpoints:**
    - **Coarse-grained:** Use `#[Authorize(...)]` on controllers/actions to require a role (
      see [references/authorize.md](references/authorize.md)).
    - **Fine-grained:** Inside actions or services, call `can($permissionCode)` when only some operations require extra
      permission.

## 3. When changing permissions

- **Adding a new permission:**
    - Define a new permission code (string) that follows your app’s naming convention (often dot-separated, lowercase).
    - Attach that code to the relevant entities (e.g. menu item, RBAC permission table).
    - Update role-permission assignments so at least one role has that permission.
    - Use `can($permissionCode)` or `#[Authorize]` where the permission is enforced.
- **Renaming a permission:**
    - Update the code in all places: entities/tables, menu/route definitions, and any code or config that refers to it.
    - Keep names consistent with existing patterns (package or app docs are authoritative).
- **Removing a permission:**
    - Remove or mark as deprecated in the RBAC tables/config.
    - Update any menu items, controllers, or widgets that referenced it to avoid dead checks.

## 4. Verify RBAC usage

When the user asks to check RBAC/permissions:

1. For a given endpoint or UI element, identify its permission code (from menu/permission tables or config).
2. Check which roles have that permission (from RBAC tables or repositories).
3. Check how the app enforces it:
    - Coarse-grained: controller/action `#[Authorize]`.
    - Fine-grained: `can($permissionCode)` in widgets or services.
4. Report whether the effective behavior matches the described requirement (who should see/do what).

## 5. Cross-reference

- **Authorization attributes:** See [references/authorize.md](references/authorize.md) for `#[Authorize]` usage on
  controllers/actions.
- **Views and widgets:** See [references/view.md](references/view.md) for how widgets work and where to inject
  `AuthorizationInterface` to filter menu entries.

