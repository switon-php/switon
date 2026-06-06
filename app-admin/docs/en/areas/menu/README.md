# Menu Area

The Menu area manages grouped navigation entries for the backoffice UI.

## Scope

- Menu groups
- Menu items
- Permission-code binding to navigation entries

## Scan

- Menu item scan reads controller `indexAction` view routes and creates missing menu entries automatically.
- Known app routes receive default group, item name, and icon so the first scan is usable without hand-fixing blank records.
- Blank metadata on existing records is backfilled during scan.
- Root path `/` is skipped intentionally, so workbench-style home routes do not pollute the menu table.

## Next

- Add detailed API and UI behavior docs when menu customization grows.
