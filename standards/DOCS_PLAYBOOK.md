# Docs Playbook

Repeatable workflow for `packages/{name}/docs/` component docs.

## Intent

Use this playbook when a component doc needs to be rewritten, shortened, or aligned with the current `packages/log/docs/`
shape.

The goal is not to document everything. The goal is to teach the 80% path clearly, keep stable concepts visible, and
push volatile detail into discovery commands, FAQ, design rationale, or PHPDoc.

## Inputs

Before writing, read:

- `packages/{name}/src`
- `packages/{name}/composer.json`
- `packages/{name}/tests`
- sibling component docs when behavior overlaps
- the component's current `README.md`, `faq.md`, and `design.md`

Do not start from old docs alone. Treat them as draft material, not as the source of truth.

## What To Extract

Build the document from user-facing questions, not from implementation order.

Capture:

- what the component helps standardize across apps
- what the reader must know to use it safely
- which concepts are stable and need explanation
- which boundaries matter for adoption
- which details are volatile and should not be frozen in prose

Ignore:

- internal execution traces
- helper classes the user never touches directly
- full option catalogs when discovery is available
- low-frequency edge cases that belong in FAQ or PHPDoc

## Writing Order

Use this order unless the component is unusually simple:

1. title plus one-sentence summary
2. `Key features`
3. `Installation`
4. `Quick Start`
5. `Terms at a glance` when the component has multiple recurring terms
6. `Core Concepts`
7. `Configuration`
8. `Common Pitfalls`
9. `Next`

Keep the reader's success path at the front. Do not bury the value summary after mechanics.

## Content Rules

- Teach the 80% path first.
- Explain each concept once.
- Prefer stable capability statements over class-by-class inventories.
- Keep examples short and runnable.
- Use discovery commands for current option surfaces.
- Do not document volatile defaults unless they are part of the public contract.
- Move implementation detail to PHPDoc or code comments when the doc starts reading like source walkthrough.
- Route rare or expert-only cases to `faq.md` instead of expanding the main guide.
- Add `Good fit` or `Non-goals` only when they materially improve adoption or boundary clarity; otherwise omit them.

## Section Roles

### `README.md`

`README.md` should answer:

- what this component does
- whether it fits
- how to get started
- what the main concepts are
- where the boundaries are
- where to go next

It should not become an option catalog, internals tour, or extension manual.

### `faq.md`

`faq.md` should contain short, high-frequency answers.

- answer in one or two sentences
- keep the question phrasing user-shaped
- route to `README.md`, `design.md`, or a discovery command when the answer would get long

### `design.md`

`design.md` should explain why the component exists and what trade-off it makes.

- focus on boundaries and rationale
- keep it short when the mechanism is simple
- do not turn it into a source-level execution trace

## Pattern For Volatile Details

When a detail changes often or has many valid shapes:

- do not freeze it in the main prose
- prefer stable nouns and capabilities
- point readers to `bash bin/console config:options '<FQCN>'` or the owning doc
- if the detail is only for the current implementation, leave it out

## Review Checklist

- The first screen explains value, fit, and boundaries.
- A reader can complete a basic usage path without reading design internals.
- Stable concepts are explained once and then linked, not repeated.
- Common mistakes are visible early.
- Volatile detail is either discoverable or omitted.
- `README.md`, `faq.md`, and `design.md` each have a distinct job.
