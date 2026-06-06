# Component (create / modify framework package)

Use when creating a new Switon **framework component** (package), adding a package to the monorepo, or modifying
component structure (interface, Context, events, ServiceProvider).

## 1. Before starting

- **Read standards:** `standards/README.md` (index) → then `COMPONENT_STANDARDS.md` + `CODE_STANDARDS.md`. Apply them.
- **Reference:** Prefer existing packages (e.g. `packages/i18n`, `packages/log`) for layout and patterns; source and
  PHPDoc are authoritative.

## 2. Where and naming

- **Path (monorepo):** `packages/{name}/` (lowercase, e.g. `packages/cache`, `packages/http`). Released as Composer
  package `switon/{name}`; apps only have `vendor/switon/{name}`.
- **Composer:** `switon/{name}` (package name). **Namespace** is **not** always `Switon\\{PascalCase(name)}`: many
  packages use a different segment (e.g. switon/validation → `Switon\\Validating`, switon/log → `Switon\\Logging`,
  switon/http-client → `Switon\\HttpClient`). Check the package's `composer.json` autoload and `src/`, or run *
  *`packages:official`** for the full list (install name ↔ namespace).
- **Interface:** Keep the public contract in the owning namespace; implementation may live beside it or in the package
  namespace when the contract is owned elsewhere (for example `Switon\\Core\\LocaleInterface` → `Switon\\I18n\\Locale`).

## 3. Structure

- **Interface first:** Define interface before implementation; keep interface minimal.
- **Layout (monorepo):**
    - `src/{Name}Interface.php`, `src/{Name}.php` (main implementation).
    - If stateful: `src/{Name}Context.php`, implement `Switon\Core\ContextAware`; store **all** state in
      Context, not in component properties.
    - Sub-components: `src/SubComponents/` (plural).
    - Events: `src/Event/` with event classes.
- **Dependencies:** `#[Autowired]`; inject interfaces, not concrete classes.

## 4. Registration

- **ServiceProvider:** `src/ServiceProvider.php` implementing `Switon\Core\ServiceProviderInterface`; in
  `register()` bind interface → implementation. Optional `boot()`.
- **Config (optional):** If the component is configurable, app config file = `config/{name}.php` (name = interface name
  without `Interface`, lowercased). See integration standards for config keys and `#[Autowired]` mapping.

## 5. Documentation and tests

- **Docs:** Write PHPDoc on the **interface** (what it does, how to use, config, events). Do not add a separate `Docs/`
  tree.
- **Tests:** `packages/{name}/tests/`; run via `bash tests/run.sh {name}` (do not run phpunit directly). See
  AGENTS.md.

## 6. CLI tools (verify integration)

- If the component exposes CLI commands: **list** / **tool:list** / **tool:description** to verify AI-facing tools (
  see [command.md](command.md), [tool.md](tool.md)).

## 7. Checklist (create)

- Interface in component namespace; implementation implements it.
- If stateful: Context class + `Switon\Core\ContextAware`; no state in component properties.
- Events in `Event/` if needed.
- ServiceProvider binds interface → implementation.
- Config file only if needed; name and keys match integration conventions.
- PHPDoc on interface; no standalone doc tree.
- Tests run via run.sh.

## Source of truth

- `standards/COMPONENT_STANDARDS.md`, `standards/CODE_STANDARDS.md`; existing `packages/`* layout and PHPDoc.
