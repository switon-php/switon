# Design Rationale {#design-rationale}

`switon/http` standardizes one application HTTP boundary before it exposes runtime-specific details.

## See also {#see-also}

- [HTTP Package](README.md) — main guide and configuration
- [FAQ](faq.md#http-faq) — common questions and troubleshooting
- [Context Package](../../context/docs/README.md) — scoped mutable state for long-lived services (contrast with request attributes)
- [DI Package](../../di/docs/README.md) — autowiring for controller and listener dependencies
- [Router Package](../../router/docs/README.md) — route mapping attributes used on controllers

---

## Core Problem {#core-problem}

HTTP structure drifts quickly when each app decides routing, input handling, response shaping, and cross-cutting hooks separately:

- controller code starts mixing transport parsing with business work
- request-wide concerns get implemented in different styles
- runtime-specific assumptions leak into normal application code

## Primary Goal {#primary-goal}

The package keeps the main HTTP shape stable across apps and runtimes:

- **Benefit:** controller actions stay easy to read and copy
- **Benefit:** request-wide behavior stays centralized
- **Cost:** some low-level runtime tricks stay outside the default path

## Why This Shape {#why-this-shape}

- Controllers own route declarations and action entry points.
- `Switon\Http\RequestInterface` owns request reads.
- `Switon\Http\ResponseInterface` owns response writes.
- Lifecycle events own request-wide hooks.
- `Switon\Http\ServerOptions` owns runtime selection.

That keeps the framework boundary visible without forcing every app into a large internal model.

## Typed Input And Response Boundaries {#typed-input-and-response-boundaries}

Typed input classes move repeated request parsing out of actions.

- `#[Switon\Http\Attribute\RequestBody]` keeps body input explicit
- `#[Switon\Http\Attribute\RequestQuery]` keeps query input explicit
- `#[Switon\Http\Attribute\RequestData]` keeps merged input explicit

Response shaping stays separate so controllers can return arrays for the common JSON path and use `ResponseInterface` only when the HTTP shape really matters.
