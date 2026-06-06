# Entity (create / update / verify)

Use when the user asks to create, update, verify, or modify an entity in the app.

## 1. Table and entity list (`entities.md` doc)

- **Role:** Table↔entity mapping. Create if missing (title + bullets + columns: Table, Entity, Notes).
- **Before create/change:** Read it. Do not add entity for table with Entity `—`.
- **Add entity** → add row.
- **Remove entity** (table exists) → set Entity to `—`; drop row only when table dropped.
- **Rename** → update row. Do not list fillable; source of truth is the entity class.

## 2. Where and base class

- **Location (recommended):** `app/Entity/` (`App\Entity`) or Area `app/Areas/{Area}/Entity/` (
  `App\Areas\{Area}\Entity`).
- **Base (recommended):** Extend `App\Entity\Entity`; if missing, create it extending `Switon\Orm\Entity`. One class per
  file; filename = class name.

## 3. Class and table

- **Name:** Singular (e.g. `User`, `OrderItem`). One table/record.
- **Table:** Always `#[Table('table_name')]`. **Connection:** `#[Connection('name')]` only if not default (sharding e.g.
  `db:user_id%4`).
- **Primary key:** `#[Id]` on the key property. Non–auto-increment: `#[Id(strategy: 'uuid-v7')]` etc. (see Orm docs).
- **Omit table columns:** Comment only (e.g. `// Table columns not mapped: legacy_id`) so they are not re-added.
- **Property not persisted:** Use `#[Transient]`.
- **Relations:** See [references/relation.md](references/relation.md). Rule: relation property → matching attribute; to
  remove → comment only (e.g. `// Relation not mapped: user`).

## 4. Properties and attributes

- **Public properties** = columns or relation names. Type-hint; add ORM/validation attributes as needed.
- **Updating entity:** Do not change existing attributes; only sync what changed (maxLength, new/dropped column). Do not
  add/remove `#[Fillable]` unless user asks or requirement changed.
- **ORM attributes:**
    - Property: `#[Id]`, `#[Column]`, `#[Fillable]`, `#[Transient]`
    - Relation: see [references/relation.md](references/relation.md) (`#[BelongsTo]`, `#[HasOne]`, `#[HasMany]`,
      `#[HasManyToMany]`, …)
    - Validation: `#[Immutable]`, `#[Unique]`, `#[Exists]`
    - Class: `#[Table]`, `#[Connection]`, `#[ReferencedKey]`, `#[Repository]`, `#[NamingStrategy]`, `#[DateFormat]`
    - See `Switon\Orm\Entity` and `Switon\Orm\Attribute\*` (switon/orm).
- **Optional:** Other interfaces, `onEvent()`, helpers. See `Switon\Orm\Entity` PHPDoc.

### 4.1 Fillable

- **Runtime:** Only `#[Id]` or `#[Fillable]`/`#[Fillable(true)]` → fillable; `#[Fillable(false)]` → explicitly not. Do
  not mark timestamp/audit as fillable.
- **When generating:** For properties with validation (e.g. `#[MaxLength(n)]`), default add `#[Fillable]` and **put it
  last** (e.g. `#[MaxLength(15), Fillable]`). Use `#[Fillable(false)]` only when field is not from form/API (e.g.
  log/audit). Properties without validation → no Fillable. `#[Column('name')]` = column name mapping only.

## 5. Repository

- **Paired with entity:** Create/remove/rename in lockstep. `App\Entity\{Name}` → `App\Repository\{Name}Repository` (
  Area: `App\Areas\{Area}\Repository\{Name}Repository`). Interface `{Name}RepositoryInterface` if used.
- **Shape:** Extend `Switon\Orm\Repository`, `@extends Repository<EntityClass>`, empty body. All persistence via
  repository.

## 6. CLI tools (entities)

- **Entry:** App CLI uses `bash bin/console` as the canonical form. On Windows, run the equivalent command via
  `php switon.php`. **CLI only provides data.** Whether to generate, skip (—), or update docs → decide with this skill
  and entities.md. Preprocessing → add a command, not a one-off script.
- **Never use entity:make.** Create entities manually with this skill.
- **entity:spec** — `entity:spec [connection] [--plain] [--table=filter]`
    - Connection: omit = all connections; else one.
    - Output: per connection → `{ table, class, primaryKey, properties }`.
    - `--table`: omit = all tables; `,` = explicit list (e.g. `admin,user`); else glob, auto-append `*` (e.g. `rbac` →
      rbac*).
    - Table names only → `entity:list`.
- **From spec to code:**
    - `table` → `#[Table('table')]`
    - `class` → short name + target namespace (spec often `App\Entity\`*;)
    - `primaryKey` → `#[Id]` on those properties
    - Property with `maxLength` → `#[MaxLength(n)]`, and default add `#[Fillable]` last (e.g.
      `#[MaxLength(n), Fillable]`); if that field is log/audit (not form/API), use `#[Fillable(false)]` instead.
      Properties without validation → no Fillable.
    - Repository: same namespace, `{ShortName}Repository`

## 7. Common maintenance

- **Schema changed:** Add/remove/rename properties; dropped columns → `// Table columns not mapped: ...`. Keep existing
  attributes; only sync type/maxLength when schema actually changed.
- **Move entity:** Update namespace, path, repository, and the entities list doc (`entities.md`), plus references (
  controllers, other entities).
- **Sync entities.md:** On request, compare entity files with that doc; report and fix missing/mismatched rows.

## 8. Verify

- **Fields in sync with table:** Properties match table columns (types, names); omitted columns documented in
  `// Table columns not mapped: ...`
- Extends app base or `Switon\Orm\Entity`; singular name; correct namespace
- `#[Id]` on PK; `#[Table]` present; `#[Connection]` if not default
- Relation attributes consistent (see [references/relation.md](references/relation.md))
- Optional: row in entities.md
- Report OK or list issues

## 9. List

- **List entities:** From entities.md or entity classes; optionally list fillable per entity.
- **List fillable:** From class (`#[Id]`, `#[Fillable]`); do not write into entities.md.

## Source of truth

- `Switon\Orm\Entity`, `Switon\Orm\Attribute\`* (switon/orm), app base entity and existing entities. Implementation and
  PHPDoc are authoritative.
