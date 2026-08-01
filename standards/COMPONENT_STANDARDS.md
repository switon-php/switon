# Component Development Standards

For component architecture only. DI, interfaces, visibility, exceptions, and source-level PHPDoc are out of scope here.

## Recommended component layout

Source of truth: `packages/{component}/src`.

```text
packages/{component}/src/
├── ComponentInterface.php
├── Component.php
├── ComponentContext.php        (if stateful)
├── Event/                      (if the component emits events)
├── Subsystem/                  (when multiple implementations/helpers are needed)
└── ServiceProvider.php         (when explicit bindings are needed)
```

### Layout rules

- Put the public interface in the component root namespace.
- Keep the default implementation easy to discover.
- Use subdirectories for alternative implementations, helpers, events, relations, or collectors.

## Stateful components

If a component stores request or runtime state:

- implement `ContextAware`,
- keep mutable state in `ComponentNameContext`,
- express context lifetime with the narrowest fitting marker interface, such as `ContextIsolated` or `ContextConnScoped`,
- do not keep cross-request state in service properties.

This section defines component shape only, not the full runtime-state rule set.

## Events and extension points

Use `Event/` when the component exposes meaningful lifecycle hooks or integration boundaries.

- Name events by lifecycle meaning, not by low-level implementation detail.
- Use event FQCNs across dispatch, listeners, and docs.
- Prefer adding events at stable extension points instead of requiring subclasses to patch internals.
- Emit events at stable lifecycle or integration boundaries, not at temporary implementation steps.
- Prefer reusing event boundaries for observability before scattering direct logs through business flow.
- Keep event and log payloads to stable, serializable summaries; do not dump heavy runtime objects directly.

This section covers event placement only, not the general logging policy.

## Documentation ownership

- **Interface PHPDoc:** stable API contract, usage constraints, navigation links.
- **`packages/{name}/README.md`:** lightweight guide page only.
- **`packages/{name}/docs/`:** component owner docs (`README.md`, `faq.md`, `design.md`). Narrative guides/tutorial stay under `docs/en/**`.

This section assigns doc ownership only; it does not define PHPDoc or Markdown style rules.
