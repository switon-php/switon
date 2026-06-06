# Extension Guidelines

This page defines extension boundaries for app-admin areas.

## Allowed Extensions

- Add new area-specific tables with clear prefixes.
- Add area-specific controllers and views under `app/Areas`.
- Add non-breaking fields to existing entities.

## Restricted Changes

- Do not repurpose existing core fields with incompatible semantics.
- Do not bypass shared validation rules in one area only.
- Do not couple area internals through direct table reads when repositories already exist.

## Compatibility Strategy

- Prefer additive schema changes.
- Keep old fields readable during migration windows.
- Document behavior changes in `docs/en/changelog.md`.
