# PHPDoc Standards

For source-level PHPDoc in `packages/*`.
Markdown doc structure is out of scope here.

## Goal

- Describe stable contracts and source navigation.
- Keep volatile flow details out of summaries.
- Keep tutorials and narrative usage guides out of PHPDoc.

## Optimization Priority

- Optimize DI and factory boundaries first: named services, container-facing resolution, and cross-layer handoffs.
- Then optimize entry classes: public interfaces, providers, factories, dispatchers, and other stable hop points.
- Then optimize `@see` meshes where a docblock needs a next hop more than a description.
- Finally trim repetitive property annotations, especially autowired dependency slots that only repeat native types.
- Do not rewrite low-signal PHPDoc just to increase volume or coverage.

## Property PHPDoc

- Typed `#[Autowired]` properties usually do not need `@var`.
- Add `@var` only when PHPDoc adds precision beyond the native type, for example `@var array<string, string>`.
- Most property PHPDoc should be one line.
- Property PHPDoc is mainly for configuration-injected scalar or array properties.
- Interface-typed dependency properties usually need no property PHPDoc.
- If a property doc only repeats the native type or injected role, remove it unless it helps navigation.
- Move multi-line rationale to class-level PHPDoc.

## Method PHPDoc

- Public methods: write from the caller perspective.
- Document purpose, inputs, outputs, `@throws`, side effects, and recommended usage when they are not obvious.
- Protected overridable methods: document override expectations and call timing.
- Protected internal helpers: explain only non-obvious logic, invariants, or edge cases.
- `*Command` classes and `*Action` methods: the first summary line and `@param` text are CLI help; keep them plain and action-oriented.
- If a signature stays broad for compatibility, document the recommended caller contract.

## Class-level PHPDoc

- The first sentence should describe stable purpose and role only.
- Prefer natural lead-ins such as `Use when`, `Configure with`, and `Output mode`.
- Summarize configuration by stable concern groups, not exhaustive key lists.
- Keep cache paths, lazy-load notes, one-time timing details, and low-level flags out of summaries.
- Keep wording stable across refactors.

## Guidance, Road-signs, and `@see`

### Guidance

- Format: `Guidance: ...`
- One sentence only.
- Place it on the closest stable misuse boundary.
- Include stable anchors such as package, class, event, config key, or syntax.

### Road-signs

- Format: `Road-signs:` followed by up to 5 bullets.
- Use for recurring tasks or mechanisms, not one-off bug notes.
- Keep bullets short; keep full targets in `@see`.
- Prefer mechanism entrypoints and cross-boundary hops over “methods in this class”.

### `@see`

- Point only to resolvable FQCN symbols.
- Do not duplicate the same edge in one docblock.
- Treat `@see` as curated, not exhaustive.
- When answering from `@see`, phrase it as `per @see` or `documented:` rather than implying completeness.
- Framework packages must not add `@see` edges to app code such as `\App\...`.
- Reverse `@see` edges are allowed only for few, high-signal consumers.

### `@see` review

- If an existing `@see` misleads, report the exact tag, the actual 2–4 hop path, and a minimal patch suggestion.
- Common triggers: unrelated target, stale default label, missing next hop, or a runtime path that no longer matches the tag.

## Inline code

- Use Markdown backticks for identifiers, literals, and wire keys: `` `name` ``.
- Do not use `{@code …}` — PhpStorm Quick Doc does not render it.
- Leave `@codeCoverageIgnore` alone; it is a PHPUnit attribute tag, not inline markup.

## Type precision

- Keep native PHP types on signatures and properties.
- Use PHPDoc types when native types cannot express enough precision.
- Do not duplicate native scalar or class types unless PHPDoc adds real meaning.

## Exception and event PHPDoc

- Exceptions: default to one sentence describing the boundary only.
- Expand only when behavior is non-obvious, for example status mapping, protocol contracts, or factory/context rules.
- Events: keep to one-sentence purpose plus `Log category:` when useful.
- Do not turn exception or event PHPDoc into catalogs, flow walkthroughs, or listener tutorials.
- Event payload boundary: keep event fields to semantic facts first. Do not require environment context (`scene`, `app_id`, `timestamp`, `host`) on every event type.
- For operational failure events, use a small diagnostic contract (for example reason code, operation, target). Environment context should be injected by listeners/exporters when needed.

Preferred compact exception shape:

```php
/**
 * Use when <specific scenario>.
 * Cause: <non-obvious root cause>.
 * Fix: <non-obvious check or fix>.
 */
```

## Quick checklist

- Stable first sentence on classes
- Caller perspective on public APIs
- Minimal property docs
- Concise exception and event docs
- Resolvable `@see`
- Inline code with backticks, not `{@code}`
- `Guidance` and `Road-signs` only where they add real navigation value
