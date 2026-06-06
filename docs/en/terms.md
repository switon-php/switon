# Terms {#terms}

Internal localization support file for `docs/en/**`.

Use this page as the English terminology baseline for localized docs.
Keep it short, focused on high-frequency or high-risk terms, and update it only when a term repeatedly causes drift.
This file is **not** part of the component mirror set (`README.md` / `faq.md` / `design.md`) and should not be treated as user-facing package navigation.

## Rules {#rules}

- Do **not** translate code-like identifiers: FQCNs, methods, config keys, CLI commands, constants, paths, URLs, and anchors.
- Keep this file selective: record only repeated, decision-shaping terms.
- Prefer one stable term per concept unless a real distinction is required.
- If a component intentionally needs a narrower wording, note that in the component docs instead of overloading this glossary.

## Core terms {#core-terms}

| English term | Preferred use | Avoid / watch | Notes |
|---|---|---|---|
| `bootstrap` | `bootstrap` | over-explaining it as a generic startup synonym everywhere | Use for the framework initialization phase or path. |
| `startup` | `startup`, `app startup`, `startup path` | mixing it with `bootstrap` when order matters | `startup` is broader; `bootstrap` is the initialization phase inside it. |
| `runtime` | `runtime`, `runtime-specific`, `runtime boundary` | vague synonyms like “mode” when the execution environment is meant | Use for FPM / Swoole / CLI / custom runtime contexts. |
| `boundary` | `boundary`, `trust boundary`, `validation boundary` | switching between unrelated words for the same design boundary | Keep the modifier explicit when needed. |
| `fallback` | `fallback` | softening it into unclear wording like “alternative thing” | Usually means the secondary path used when the primary path is absent or fails. |
| `payload` | `payload` | translating it into non-technical wording when token/body shape matters | Keep `payload` in JWT / HTTP / serialization contexts. |
| `binding` | `binding` | mixing it with unrelated terms like “wiring” or “mapping” | Use `binding` for DI or parameter binding unless the context requires a narrower term. |
| `provider` | `provider`, `service provider` | replacing it with business-domain words like “supplier” | Keep the technical framework meaning. |
| `worker` | `worker`, `worker process` | hiding the process-scoped meaning | Use `worker process` when process lifetime matters. |
| `dispatch` | `dispatch`, `dispatches`, `is dispatched` | ad hoc synonyms when the event-system action is meant | Prefer stable event-language around `dispatch()`. |
| `event dispatcher` | `event dispatcher` | alternating between dispatcher, bus, and emitter for the same concept | Keep it aligned with `Psr\EventDispatcher\EventDispatcherInterface`. |
| `fail-fast` | `fail-fast` | verbose paraphrases that blur the contract | Use when early hard failure is the design point. |
| `service-definition map` | `service-definition map` | flattening it to “config” when structure matters | Use when the doc talks about merged DI definitions. |
| `service ID` | `service ID` | inconsistent variants like service name / key when the container ID is meant | Keep it explicit in DI/config docs. |
| `collector` | `collector` | introducing a new architectural label casually | Use only when the config entry really acts as a collector. |
