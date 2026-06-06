# Design Rationale {#design-rationale}

`Dict` standardizes one recurring problem in Switon apps: controlled value sets drift across action parameters, validation, and UI option lookup.

## See also {#see-also}

- [Dict Package](README.md) — usage, configuration, and the public boundary
- [FAQ](faq.md#dict-faq) — fit, boundaries, and common questions

---

## Why This Exists {#why-this-exists}

Applications usually know how to store controlled values already. The inconsistency appears in how those values are used.

- one controller accepts a raw scalar
- one form keeps a local options array
- one input object normalizes the same concept another way
- one reader exposes labels through a custom helper

`Dict` targets that drift first.

## What Switon Standardizes {#what-switon-standardizes}

The package makes these entry points converge:

- typed action parameters via `DictResolver`
- scalar field validation and normalization through `#[DictValue]`
- UI option lookup through `OptionReaderInterface`
- a single `builtin` lookup flag that lets one reader serve framework-owned and app-owned dict data without renaming types

That keeps the usage shape stable even when the underlying source changes.

## What Stays Outside {#what-stays-outside}

`Dict` does not define one required schema, admin UI, or built-in business content.

Those concerns stay with the application or adapter layer. The framework only standardizes the read-side contract and the typed boundary around it.
