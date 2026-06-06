# Design Rationale {#design-rationale}
`switon/openapi` keeps API documentation aligned with controller routes while leaving hand-edited YAML in place.

## See also {#see-also}

- [OpenAPI Package](README.md) — setup, workflow, and command surface
- [FAQ](faq.md#openapi-faq) — common questions and troubleshooting

## Why This Exists {#why-this-exists}

API docs drift when controller mappings, `operationId` naming, and YAML files each follow different rules. This package standardizes one source-aligned path so the document stays close to the code that owns the route.

## Main Trade-Offs {#main-trade-offs}

- split files are easier to maintain than one giant spec
- sync is conservative instead of rewriting YAML for you
- `operationId` stays aligned with Switon handler ids, so naming freedom is smaller but tooling stays consistent
- export can dereference local refs, but it does not try to become a full external bundler

## Deliberate Limits {#deliberate-limits}

- `open-api:sync` adds missing stubs only
- `open-api:export --deref` resolves local `#/...` refs only
- lint warns on weak spec structure, but it still keeps the authoring loop light
