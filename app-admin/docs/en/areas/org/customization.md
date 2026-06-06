# Org Customization

## Allowed

- Hide optional fields.
- Customize list columns and default filters.
- Add display-only derived fields.

## Not Allowed

- Changing relation semantics in UI.
- Using display name as business key.
- Bypassing `*_code` uniqueness.
- Hiding required fields that break assignment integrity.

## Rule

- Keep schema stable; customize only presentation and defaults.
