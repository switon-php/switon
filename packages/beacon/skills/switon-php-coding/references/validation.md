# Validation (add / change / verify)

Use when the user asks to add or change **validation rules**, form validation, DTO validation, or constraint attributes
on entity properties.

## 1. Where validation applies

- **Entity properties:** Attribute on the property (e.g. `#[MaxLength(32)]`, `#[Email]`). If the field is from form/API,
  add `#[Fillable]` last (see entity topic in this skill). ORM-specific: `#[Unique]`, `#[Exists]`, `#[Immutable]` from
  switon/orm.
- **DTO / request body:** Class with `#[RequestBody]`; properties use the same constraint attributes. Invoker resolves
  and validates before controller action. Non-nullable property without default gets `Required` automatically if not
  explicitly set.
- **Manual:** Inject `Switon\Validating\ValidatorInterface`, call `validateValue()` or `validateValues($input, $rules)`;
  throws `Switon\Validating\Exception\ValidateFailedException` on failure.

## 2. Packages and namespaces

- **switon/validation:** Constraints live under `Switon\\Validating\\Attribute\\*`. Use these on entity properties and
  DTO properties.
- **switon/orm:** `#[Unique]`, `#[Exists]`, `#[Immutable]` on entities only. See entity topic for placement with
  `#[Fillable]`.

## 3. Constraint catalog (quick reference)

| Group            | Constraint                                                 | Example / note                   |
|------------------|------------------------------------------------------------|----------------------------------|
| Presence         | `Required`, `NotEmpty`, `Defaults`                         | `Required()`, `Defaults('')`     |
| String           | `Length`, `MinLength`, `MaxLength`                         | `Length(4, 16)`, `MaxLength(32)` |
| String/normalize | `Trim`, `Lowercase`, `Uppercase`                           | `Trim()`                         |
| Numeric          | `Min`, `Max`, `Range`, `Decimal`                           | `Range(1, 100)`                  |
| Format           | `Email`, `Url`, `Ip`, `Uuid`, `Regex`, `Mobile`, `Account` | `Email()`                        |
| Set              | `In`, `NotIn`, `ConstantValue`                             | `In(['a','b'])`                  |
| Other            | `Type`, `EqualTo`, `StartsWith`, `EndsWith`                | `Type('string')`                 |

Full list and constructor args: `Switon\\Validating\\Attribute\\*`; see attribute PHPDoc for complete options.

## 4. Rules

- **Order:** On a property, put normalization (Trim, Lowercase, etc.) and presence (Required, Defaults) before
  format/length; put `#[Fillable]` last when used with entity.
- **Entity:** Do not add/remove validation attributes when only doing unrelated updates; sync only when requirement or
  schema (e.g. maxLength) changes.
- **Errors:** Validation throws `Switon\\Validating\\Exception\\ValidateFailedException`; message templates and locale
  are defined in the switon/validation package (see its source and configuration).

## 5. Source of truth

- switon/validation (Attribute/*); switon/orm (Attribute/Unique.php, Exists.php, Immutable.php). In app:
  vendor/switon/validation, vendor/switon/orm. Implementation and PHPDoc are authoritative.
