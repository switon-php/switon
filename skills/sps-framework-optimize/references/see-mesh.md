# PHPDoc @see mesh rules

This reference defines how to maintain Switon’s PHPDoc `@see` mesh for AI navigation.

## Goals

- Make “what to open next” obvious.
- Prefer stable, low-churn anchors (contracts, entrypoints, events, exceptions).
- Avoid turning `@see` into an exhaustive index.

## Ordering (contract-first)

In class-level PHPDoc, prefer this order:

1. **Contract** (`XxxInterface`)
2. **Key action method** (rare, see below)
3. **Primary implementation** (`Xxx`)
4. **Mechanism peers** (dispatcher/handler/context/event)
5. **Exit points** (exceptions/events)

## Layered jumps

Prefer:

- `A → B` then `B → C/D`

Avoid:

- `A → C/D` when `B` is the correct next hop

## Interface method-level @see (noise control)

Interfaces are discoverable by signature. Use method-level `@see` only as a “fast landing” for a mechanism entry.

Rules:

- Interface class-level PHPDoc: keep **≤ 2** `@see \Switon\...\Interface::method()` entries.
- Only use method-level `@see` for **mechanism entry / key action** (examples: `::start()`, `::invoke()`, `::match()`, `::bootstrap()`).
- If you find 3+ method-level entries, convert extras to class-level `@see` (no `::method()`), or remove.

## Reverse edges (cross-package)

If consumers are few and stable, add sparse reverse edges:

- From dependency B → typical consumer A, **1–3 entries** only.

Do not:

- Add long consumer lists to shared hubs.

