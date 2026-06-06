# Relation (define / change / verify)

Use when the user asks to add, change, remove, or verify relations on entities.

## 1. When to use

- Define a new relation on an entity
- Change or remove an existing relation
- Verify relation attributes and properties are consistent
- Decide which relation type to use (BelongsTo vs HasMany vs HasManyToMany, etc.)

## 2. Rules

- **Property + attribute:** Every relation is a **public property** with a **matching relation attribute**. No property
  without attribute; no attribute without property.
- **Type hint:** Property must have correct type (`?Entity` for single, `array` for collection). For collection (
  `array`), add PHPDoc element type: `/** @var Entity[] */` or `/** @var list<Entity> */` so IDE/static analysis know
  the item type.
- **Remove relation:** Do not delete. Comment only (e.g. `// Relation not mapped: user`).
- **Source of truth:** `Switon\Orm\Attribute\`* and `Switon\Orm\Relation\*` (switon/orm). PHPDoc on attributes is
  authoritative.

## 3. Relation types

| Type              | Attribute                                | FK / junction           | Return                                |
|-------------------|------------------------------------------|-------------------------|---------------------------------------|
| Many-to-one       | `#[BelongsTo]`                           | FK on current entity    | `?Entity`                             |
| One-to-one        | `#[HasOne]`                              | FK on related entity    | `?Entity`                             |
| One-to-many       | `#[HasMany]`                             | FK on related entity    | `array` + `@var Entity[]`             |
| Many-to-many      | `#[HasManyToMany]` or `#[BelongsToMany]` | Junction entity         | `array` + `@var Entity[]`             |
| Through           | `#[HasManyThrough]`                      | Via intermediate entity | `array` + `@var Entity[]`             |
| Junction (custom) | `#[JunctionMany]`                        | Custom junction         | `array` + `@var Entity[]`             |
| Polymorphic       | `#[MorphTo]`, `#[MorphMany]`             | Type + id columns       | `?Entity` / `array` + `@var Entity[]` |

- **BelongsTo:** FK in current table; omit param → inferred from related `#[ReferencedKey]` or default.
- **HasMany / HasOne:** FK in related table; omit `foreignKey` → inferred from current entity's `#[ReferencedKey]`.
- **HasManyToMany:** Needs junction entity with two `#[BelongsTo]`; framework infers keys from junction. See attribute
  PHPDoc for params.
- **Polymorphic:** Needs `*_type` and `*_id` (or similar) columns; see `MorphTo` / `MorphMany` PHPDoc.

## 4. Verify

- Each relation property has exactly one relation attribute.
- Types match (single → `?Entity`, collection → `array` with `@var Entity[]` or `@var list<Entity>`).
- BelongsTo: FK property exists on current entity. HasMany/HasOne: FK exists on related entity. HasManyToMany: junction
  entity and its BelongsTo exist.
- Removed relations only commented out, not deleted.
- Report OK or list issues.

## 5. Cross-reference

- **Entity (this skill):** Placement of entities, `#[Table]`, `#[Id]`, columns, fillable, repository. For "add/change
  relation" → use this reference; [references/entity.md](references/entity.md) still applies for file/namespace/base
  class.
- **switon/orm:** `Switon\Orm\Entity`, `Switon\Orm\Attribute\BelongsTo`, `HasOne`, `HasMany`, `HasManyToMany`,
  `HasManyThrough`, `JunctionMany`, `MorphTo`, `MorphMany`, `ReferencedKey`; `Switon\Orm\Relation\`*.
- **CLI helpers:** For table/entity overview use the entity CLI tools (`entity:spec`, `entity:list`); see the CLI
  section in [references/entity.md](references/entity.md). Do not guess columns.
