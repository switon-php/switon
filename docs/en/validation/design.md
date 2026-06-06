# Design Rationale {#design-rationale}

`switon/validation` keeps validation close to the field contract: one shared validator, small constraints, and clear
message shaping.

## See also {#see-also}

- [Validation Package](README.md) — usage, configuration, and the public contract
- [FAQ](faq.md#validation-faq) — short answers for setup and common mistakes

---

## Core Problem {#core-problem}

Validation drifts fast when every input source invents its own rules and message style.

- one path trims first, another validates raw text
- one path returns normalized values, another does not
- one path uses raw field names, another uses local labels
- source selection gets mixed into field rules

The package standardizes the field boundary first.

## Why One Validator {#why-one-validator}

Manual checks and framework entrypoints use the same `ValidatorInterface`.

That keeps semantics stable across service code, typed input binding, and other call sites. The trade-off is that
validation stays focused on field rules instead of becoming a broader input planner.

## Why Constraints Can Normalize {#constraints-normalize}

Rules like `Trim`, `Defaults`, `Type`, `Email`, `Date`, `Lowercase`, and `Uppercase` can change the current value.

That makes normalization part of the rule contract instead of a separate pre-pass. The trade-off is that order matters.

## Why Message Keys Use Real Symbols {#keys-real-symbols}

`AbstractConstraint` falls back to `static::class` for the message key.

That keeps locale lookup aligned with the actual code symbol and avoids a second alias registry. Literal message strings
still work when you want a one-off sentence.

## Why Labels Stay Separate From Error Keys {#separate-error-keys}

Human-facing text and machine-facing field keys are not the same thing.

Validation resolves labels through constraint labels, template labels, and i18n keys, but it keeps the original field
path in the error map. That gives readable messages without losing stable keys for callers.

## Deliberate Boundary {#deliberate-boundary}

Validation does not choose where input comes from.

- `switon/http` owns body, query, and merged input selection
- `validation` owns field rules, normalized values, and error text
- typed input binding is a consumer, not the owner

That split keeps the package small and easier to reuse outside HTTP.
