# Dict FAQ {#dict-faq}

Short answers for fit, boundaries, and reader setup.

> **Prerequisites:** [Dict Package](README.md)

## Fit {#fit}

### Is `dict` a schema package? {#schema-package}

No. The package standardizes typed usage and read contracts, not one fixed table layout.

### Is `dict` only for validation? {#only-validation}

No. Validation is one path. The same dict type also drives action parameters and UI options.

### Is `dict` the same as `enum`? {#same-as-enum}

No. `enum` fits code-owned closed sets. `dict` fits runtime-controlled value sets that still need typed usage.

## Setup {#setup}

### Do I need to bind a reader? {#bind-reader}

Yes. Bind `Switon\Dict\DictReaderInterface` to your app-owned reader. Bind `Switon\Dict\OptionReaderInterface` only when UI code also needs option rows.

### How do I inspect the current option surface? {#inspect-options}

Run `bash bin/console config:options 'Switon\Dict\DictReaderInterface'`.

## Behavior {#behavior}

### What happens when the value is missing? {#missing-value}

Non-nullable action parameters fall back to the default item when one exists. Nullable parameters can return `null`. `#[DictValue]` properties do not auto-pick the default item; they normalize accepted input or leave nullable empty input as `null`.

### What if `typeCode()` is empty? {#empty-type-code}

Resolution fails. Override `typeCode()` or use a class name that can derive one.

### Can the same reader serve both dicts and options? {#same-reader}

Yes. That is the normal data pattern when one source owns both lookup and option rows.

### What does `getItem()` match? {#item-match}

It matches the stored value (`itemValue`), not `itemCode`.
