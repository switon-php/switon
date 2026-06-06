# Validation FAQ {#validation-faq}

Short answers for common `validation` questions.

> **Prerequisites:** [Validation Package](README.md)

## Getting Started {#getting-started}

### What happens when validation fails? {#validation-fails}

`ValidateFailedException` is thrown and carries the field error map.

### Do validated values come back normalized? {#normalized-values}

Yes. Rules like `Trim`, `Defaults`, `Type`, `Email`, `Date`, `Lowercase`, and `Uppercase` can change the returned value.

### Which entrypoint should I use? {#entrypoint}

Use `validateValues()` for payloads, `validateValue()` for one field, and `beginValidate()` / `endValidate()` only when
you need a manual session.
`validateValue()` still fails by throwing `ValidateFailedException` through the validation session, not by returning
`null`.

## Constraints {#constraints}

### How do I make a field required? {#required}

Use `Required`. In HTTP typed input binding, non-nullable public properties without defaults are also treated as
required.

### How do I model "optional, but if present it must be an int"? {#optional-typed}

Do not expect `Type('int')` to make a missing field optional in manual validation. Check presence first or use a
nullable/defaulted input shape.

### Can one constraint both normalize and validate? {#normalize-and-validate}

Yes. `Email`, `Date`, `Defaults`, `Trim`, `Lowercase`, and `Uppercase` are common examples.

### How do enums and constant-backed allow-lists work? {#enum-and-constants}

Use `EnumValue` for enums and `ConstantValue` for class-constant sets.

### Can I validate an object source? {#object-source}

Yes. `beginValidate()` accepts arrays and objects, and object sources can influence source-class-based labels.

## Messages {#messages}

### How do I rename field names in messages? {#field-labels}

Use constraint labels, template labels, or standard i18n label keys. This changes rendered text, not the error key.

### Why is my custom message treated as literal text? {#literal-message}

Because strings without a backslash are treated as literal templates. Use a backslash-containing key when you want
locale-file lookup.

### How do I inspect the available message options? {#discover-options}

Run `bash bin/console config:options 'Switon\Validating\ValidatorInterface'`.

### Which built-in constraints are available? {#built-in-constraints}

The built-ins include `ArrayOf` alongside presence, type, string, numeric, set, edge, and normalization constraints.

## Troubleshooting {#troubleshooting}

### Why is a missing field absent from `validateValues()` output? {#missing-output}

Because missing fields stay missing unless a rule like `Defaults` writes a value.

### Why did typed input binding mark a field as required even without `#[Required]`? {#auto-required}

Because `switon/http` treats non-nullable public properties without defaults as required input.

### Can I validate nested input objects and arrays of input objects? {#nested-validation}

Yes. `ArrayOf` handles array element typing, and nested failures use dot paths.

### What other validation exceptions should I expect? {#other-exceptions}

`LocaleTemplateNotFoundException`, `InvalidConstraintTargetException`, `InvalidConstraintSourceException`,
`UnsupportedValidationTypeException`, and `ConstraintViolationException` can all surface depending on the constraint
path.
