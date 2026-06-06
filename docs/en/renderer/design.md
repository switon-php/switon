# Design Rationale {#design-rationale}
`switon/renderer` keeps template state explicit instead of hiding it inside a long-lived rendering service.

## See also {#see-also}

- [Renderer Package](README.md) — render transactions, engines, and template composition
- [Renderer FAQ](faq.md#renderer-faq) — short answers about `Frames`, themes, and Sword

---

## Why This Package Exists {#purpose}

Template rendering usually involves more than final HTML.

- sections and stacks need shared state
- partials need access to the current transaction
- theme lookup needs one consistent rule
- compiled templates need a clear runtime boundary

This package makes that state visible through `Frames`.

## Why `Frames` Comes First {#frames-comes}

`Frames` is the render result and the shared transaction object.

That gives one contract for:

- input vars
- content
- sections
- stacks
- once markers
- theme

The trade-off is that rendering returns an object instead of only a string, but the benefit is explicit state
composition.

## Why Engines Stay Separate {#engines-separate}

`Renderer` chooses the engine from the file extension.

That keeps `.phtml` and `.sword` on the same render path while still letting each template format keep its own
authoring style.

## Why Top-Level Capture Stays On The Renderer {#level-capture-renderer}

Renderer still owns the top-level output buffer and guards against unsafe nested capture in the same execution
context.

That boundary keeps fragile capture logic out of templates and leaves fragments, sections, and stacks to the
transaction object.

## What The Package Leaves To The Application {#outside}

It does not own controller routing.

It does not own request lifecycle conventions.

It does not force one template language.
