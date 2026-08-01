# Typed Input Binding {#typed-input-binding}

Switon turns request parsing into a typed action boundary, so body, query, or merged input can arrive as one validated shape instead of repeated manual `RequestInterface` reads inside controller code.

**Why this is a highlight:**
- `#[Switon\Http\Attribute\RequestBody]`, `#[Switon\Http\Attribute\RequestQuery]`, and `#[Switon\Http\Attribute\RequestData]` make the request source explicit at the boundary
- `Switon\Binding\InputBinder` maps nested input objects and `#[Switon\Validating\Attribute\ArrayOf]` arrays recursively instead of leaving shape assembly to action code
- validation attributes under `Switon\Validating\Attribute\*` stay on the public input properties they govern
- less manual `post()`, `query()`, and `all()` parsing means less controller drift and fewer inconsistent edge-case checks

## What this article highlights {#highlights}

- Typed input binding makes the request boundary explicit and local to the action contract.
- Source selection is declared once with `#[Switon\Http\Attribute\RequestBody]`, `#[Switon\Http\Attribute\RequestQuery]`, or `#[Switon\Http\Attribute\RequestData]`.
- Nested input objects and typed arrays keep larger payloads structured without moving parsing logic into controllers.
- Validation rules stay close to the shape through `Switon\Validating\Attribute\*` property attributes.

## Why it matters at the request boundary {#request-boundary}

Typed input binding separates two concerns that are often mixed together in controller code:

- `Switon\Http\RequestInterface` remains the raw transport boundary
- the action parameter type becomes the validated application input boundary

That split matters because the action can depend on one known shape instead of rebuilding the same field extraction, type conversion, and missing-field checks from `post()`, `query()`, or `all()` on every write path.

## Explicit source selection instead of implicit parsing {#explicit-implicit-parsing}

The source attribute is part of the contract:

- `#[Switon\Http\Attribute\RequestBody]` for body-only payloads
- `#[Switon\Http\Attribute\RequestQuery]` for query-only input objects
- `#[Switon\Http\Attribute\RequestData]` when merged route, body, and query input is intentional

This is a framework-level improvement because "where did this field come from?" stops being hidden inside action code. The source is visible on the input type before you read the method body.

## Validation stays with the shape {#validation-shape}

Switon keeps request validation close to the public properties that define the input object.

That gives one place to read:

- required versus optional fields from type and default rules
- field constraints such as `#[Switon\Validating\Attribute\Email]` or `#[Switon\Validating\Attribute\Length]`
- nested object and `#[Switon\Validating\Attribute\ArrayOf]` structure

The result is usually simpler than validating one ad hoc array in the action and then manually copying values into another object later.

## Nested payloads without controller glue code {#nested-glue-code}

`Switon\Binding\InputBinder` supports nested input objects and typed arrays recursively, so larger request bodies can stay expressed as shape instead of assembly logic.

That matters less for trivial forms and more for endpoints where manual parsing would otherwise spread across:

- nested array existence checks
- per-branch type coercion
- dot-path validation error naming

The framework keeps those concerns at the binding layer instead of repeating them in each controller.

## Practical takeaway {#practical-takeaway}

Choose typed input binding when the request payload shape is part of the action contract and you do not want parsing, coercion, and validation spread across controller bodies.

Keep `RequestInterface` for raw transport cases. Use typed input when the application should receive one explicit, validated shape.

## See also {#see-also}

- [Typed Input Binding](../../packages/http/docs/typed-input-binding.md#typed-input-binding) — source rules, nested binding, and ownership-aware input behavior
- [HTTP Package](../../packages/http/docs/README.md) — controller, request, and action-boundary context
- [Validation Package](../../packages/validation/docs/README.md) — shared validation model and constraint behavior
