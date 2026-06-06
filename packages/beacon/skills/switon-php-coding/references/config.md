# Config

Valid keys: run **config:options** `<InterfaceFQCN>` (or see docs).

- `config:options <InterfaceFQCN>` is a **default-entry discovery step**, not a full container graph dump.
- Reason: expanding every named binding, alias, factory entry, or adapter layer makes the output unstable and noisy,
  especially when one interface fans out into many runtime variants.
- Use the interface first to find the default binding and stable option entrypoint.
- If you are tuning a specific named or implementation-specific surface, query the concrete class next.
- For `#[Autowired(instances: true)]` arrays, `config:options` now shows inferred item types such as
  `array<Switon\\Redis\\ClientInterface>` so the expected entry shape is visible without reading injector code.

## 1. File layout

- `config/`, one file per concern; `env('KEY', default)` for env.
- Example: `DbInterface` → `config/db.php`, key `'Switon\\Db\\ClientInterface' => [ ... ]`.

## 2. Env keys

- `.env` = source of truth. New key → add to `.env` and `.env.example`.

## 3. Value shapes (property type → config)

- Applied by `Switon\Di\Injector` (config → `Switon\Core\Attribute\Autowired` properties).
- **Scalar:** as-is.
- **Array (plain):** Non-empty default → merge (`null` removes key). Empty/no default → replace. `Traversable` →
  override, no merge.
- **Array `#[Autowired(instances: true)]`:** Map name => string (service id) or inline array (`'class'` + params).
- **Object:** String = service id or `#name`; inline array = `make()` (params; `'class'` required when no default
  binding).

## 4. Multi-instance (Factory)

- **Implementation:** `Switon\Di\Factory`.
- **Config shape:** `'Interface' => new Factory([ 'default' => [...], 'name' => config, ... ])`. All named entries for
  one interface live under that key in one file (no splitting across files).
- **Entries:** Named instances; omit `'class'` unless overriding. Entry value can be `#name` (alias). Reference from
  other config: `'prop' => '#name'`.
- **Resolution:** Explicit `#name` → that entry (undefined name → error; we do not substitute the default entry).
  Unnamed injection → default entry; no default entry → error.
