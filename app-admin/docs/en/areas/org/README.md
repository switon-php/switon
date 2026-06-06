# Organization Area

Organization structure and assignment for `app-admin`.

## Purpose

- Provide `department`, `position`, `group`, and staff relations.
- Support RBAC composition, approval routing, and data scope.

## Dictionary-backed Fields

- `department.status`, `position.status`, and `group.status` are now resolved through `App\Areas\Org\OrgStatus`, a thin `Dict` subclass bound to `org.status`.
- `group.type` is now resolved through `App\Areas\Org\GroupType`, a thin `Dict` subclass bound to `org.group.type`.
- These fields are normalized on the entity through `#[DictValue(...)]`, so create/edit actions now only take `Department $department`, `Group $group`, and `Position $position`.
- The stored values still come from the dict reader contract, not from ad-hoc controller mapping.

## Current Naming Decisions

- Tables: `org_department`, `org_position`, `org_group`.
- Relation tables: `org_staff_department`, `org_staff_position`, `org_staff_group`.
- Subject key: `staff_id`.

## Domain Model

See [concepts](concepts.md) for boundaries and examples.

## Data Schema

See [database](database.md) for the required table structure and index rules.

## API Contract

See [api](api.md) for endpoint shape and response conventions.

## UI Surfaces

See [ui](ui.md) for default pages and customization boundaries.

## Customization Points

See [customization](customization.md) for safe extension rules.

## FAQ

See [faq](faq.md) for common implementation questions.

## Troubleshooting

See [troubleshooting](troubleshooting.md) for spinner/no-data diagnostics.

## Version and Compatibility

- Since: `app-admin` org area documentation initialization.
- Compatibility: `staff`-based subject model with explicit relation tables.
