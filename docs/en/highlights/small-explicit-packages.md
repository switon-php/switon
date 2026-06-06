# Small explicit packages {#small-explicit-packages}

Switon prefers small package boundaries, explicit contracts, and visible composition over one hidden framework box that tries to own every runtime concern at once.

**Why this is a highlight:**
- package boundaries stay readable, so adoption starts from the problem you actually need to solve
- package `README.md`, `faq.md`, and `design.md` files explain one owner boundary at a time
- contracts stay narrow enough to reuse across HTTP, CLI, workers, and application services
- composition stays in the application or package wiring layer instead of disappearing into one monolithic default

## The framework choice {#the-framework-choice}

This package shape follows the same methodology described in [Understand the Switon framework methodology](../concepts/framework-methodology.md#framework-methodology): standardize scattered usage boundaries first, but leave business ownership and local composition with the application when the framework should not hide them.

That is why Switon has many focused package docs instead of one giant runtime manual. A package usually owns one boundary clearly, such as `HTTP`, `DI`, `Context`, `Event`, `DB`, `Query`, or `ORM`, and then routes you outward when another boundary becomes the real problem.

## What small packages buy you {#packages-buy}

The main benefit is explicitness.

- package docs can say what the package owns and what it does not own
- application code can depend on small contracts instead of broad framework state
- you can combine packages without pretending every app needs the same full stack

This is also a docs choice. When one package owns one boundary, its `README.md` can stay focused on adoption, its `faq.md` can answer boundary-specific confusion, and its `design.md` can explain the trade-off without restating the whole framework.

## Contracts before hidden assembly {#contracts-hidden-assembly}

Switon generally prefers visible package contracts over framework magic that only makes sense when every part is installed together.

In practice, that means:

- the application model stays easier to trace from [Switon fundamentals](../concepts/README.md#switon-fundamentals)
- service placement stays clearer in [Understand service boundaries](../concepts/service-boundaries.md#service-boundaries)
- package selection stays explicit through [Packages](../packages.md#packages)

You read the boundary first, then compose the packages you need. The framework still gives one application model, but it does not force one giant abstraction layer to hide where `HTTP` stops, where `DI` starts, or when `Query` should stay separate from `ORM`.

## Trade-offs {#trade-offs}

This choice is not free.

- you will read more than one package doc for a larger feature
- composition decisions stay visible, so the app must choose boundaries instead of inheriting one opaque default
- some tasks feel less "all included" than in a framework that hides more behavior behind one runtime box

Switon accepts that cost because the resulting application structure is easier to reason about, easier to compare across apps, and less likely to blur business ownership into framework-owned behavior.

## See also {#see-also}

- [Understand the Switon framework methodology](../concepts/framework-methodology.md#framework-methodology) — why Switon standardizes usage boundaries first
- [Understand service boundaries](../concepts/service-boundaries.md#service-boundaries) — where application code should stop and hand off
- [Packages](../packages.md#packages) — package routing table by boundary
- [Switon fundamentals](../concepts/README.md#switon-fundamentals) — the full application model before package-by-package reading
