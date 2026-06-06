# Naming Conventions

This page defines naming conventions for app-admin areas.

## Table Naming

- Use area prefixes for clarity: `dict_*`, `rbac_*`, `menu_*`, `org_*`.
- Keep names business-readable and avoid ambiguous short words.
- Avoid SQL reserved words as raw table names.

## Field Naming

- Keep field names to at most two semantic words.
- For `*_id`, `*_code`, and `*_name`, the entity qualifier should be one word only.
- Do not use bare `id`, `code`, or `name` for business fields. Keep one distinguishing prefix word so the field stays readable outside its table context.
- Do not repeat a full multi-word table or area name inside every field.
- For multi-word table names, pick one distinguishing word from right to left as the field prefix.
- Primary keys should follow `<entity>_id`.
- Stable business keys should follow `<entity>_code`.
- Display names should follow `<entity>_name`.
- For multi-word tables, prefer a one-word qualifier such as `type_id`, `type_code`, `type_name` in `dict_type`, and `item_id`, `item_code`, `item_name` in `dict_item`.
- For boolean-like business flags, prefer a clear noun or adjective when it already reads well, such as `builtin`, instead of adding a generic `is_` prefix.

Examples:

- `department_id`, `department_code`, `department_name`
- `position_id`, `position_code`, `position_name`
- `group_id`, `group_code`, `group_name`
- `type_id`, `type_code`, `type_name`
- `item_id`, `item_code`, `item_name`

## Subject Naming

- If the product is OA-oriented, `staff` naming is preferred over `admin`.
- If keeping current app compatibility, retain `admin_id` and map to staff semantics at the domain layer.

## Time Fields

- Stay consistent with existing app style for timestamp fields.
- Keep `created_at` and `updated_at` in all mutable business tables.
