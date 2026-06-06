# Permission Model

This page defines the boundary between RBAC and organization data.

## Model Split

- RBAC defines operation permissions.
- Organization defines structural relations.
- Policies combine both at runtime.

## Integration Points

- Role assignment binds subject (`admin_id`) to role (`role_id`).
- Org assignment binds subject to department, position, and group.
- Data-scope policy can consume org assignments to limit visibility.

## Recommended Rules

- Keep permission checks code-based (`permission_code`), not name-based.
- Keep org filters id-based (`department_id`, `position_id`, `group_id`).
- Keep policy logic centralized to avoid duplicate filters across controllers.
