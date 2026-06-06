# app-admin agent rules

## Product direction

- Prioritize onboarding speed and clarity for small teams.
- Keep data models scalable enough for larger organizations.
- Use progressive disclosure in UI: simple by default, advanced when needed.

## Org area conventions

- Keep area prefix in tables: `org_*`.
- Use explicit relation tables: `org_staff_department`, `org_staff_position`, `org_staff_group`.
- Use `staff_id` as the org subject key.
- Keep fields practical: `id/code/name` with entity prefix, common fields like `display_order`, `status`, `created_at`, `updated_at`.
- Prefer `NOT NULL` with defaults over nullable fields.

## UI conventions

- CRUD pages should follow existing `m-dialog` + `m-table` patterns.
- Default forms should require only core fields and provide safe defaults.
- Keep labels concise and operations obvious for first-time users.

## Sword template conventions

- In `.sword` templates, prefer `@inject('name', FQCN::class)` for small template-level service access.
- Do not inline `\Switon\Core\App::get(...)` in `.sword` templates when `@inject` can express the dependency.
- Prefer upstream view vars for business data; use `@inject` only for light template wiring.
- Use `json(...)` for JS/JSON literal output in templates; do not hand-wrap dynamic values with quotes.

## Implementation style

- Follow existing Area structure: `Entity`, `Repository`, `Controller`, `View`.
- Keep controller actions consistent: `index`, `create`, `edit`, `delete`.
- Keep ordering stable with `display_order` + primary id.
