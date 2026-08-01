# Documentation Standards

Component SSOT: `packages/{name}/docs/**`. Narrative site: `docs/en/**` (concepts/guides/tutorial/…). Scaffolding: `DOCS_PLAYBOOK.md`.

## Why

- Docs build a **mental model** for adoption — capability lists and source walks do not.
- Markdown is for readers integrating the framework; PHPDoc owns contracts and code tracing. Do not duplicate either role.
- Published docs must not invent identifiers — wrong names break trust for split-repo readers alike.

## Hard rules

- Every machine-meaningful identifier must match code or an authoritative discovery command — do not invent classes, methods, aliases, defaults, config keys, or commands.
- Backtick all such identifiers outside fenced blocks.
- Component docs live in the package (`packages/{name}/docs/**`); after split they publish from `https://github.com/switon-php/{name}`. Narrative docs stay under `docs/en/**`.
- Same-package links stay relative (`faq.md`, `design.md`). Cross-package `## Next` and package-landing `Docs:` use **split-repo component docs only**: `https://github.com/switon-php/{name}/blob/main/docs/...`. Do not link component docs to `docs/en/guides`, tutorial, or `docs.switon.dev`.
- PHPDoc owns contracts and tracing; Markdown owns adoption, mental model, and high-level mechanics.
- `packages/{name}/docs/README.md` + `faq.md` + `design.md` = component set. `packages/{name}/README.md` = lightweight landing only.

## Component guide (`packages/{name}/docs/README.md`)

First screen: fit, value, trade-offs, boundaries — including what Switon standardizes first vs what stays in the app. Sections: `**Key features:**`, `## Installation {#installation}`, runnable Quick Start, `## Core Concepts {#core-concepts}`, `## Next {#next}`.

- Explain each concept once; link instead of repeat.
- Progressive disclosure: happy path first.
- Examples: short, runnable snippets; DI not `$container->get()` / `make()` in app examples.
- Config sections: capability-first; `config:options` targets interface FQCN when one exists; discovery commands at end.
- User-facing config examples use `switon.yml` unless PHP-only/runtime-only.
- CLI examples use installed-app entrypoints.
- No manual `**Contents:**`; no option catalog, source walk, extension manual, or migration unless essential.

## Package landing (`packages/{name}/README.md`)

`# Switon {Display Name} Package` → badges (CI + PHP 8.3+) → summary → `## Highlights` → `## Installation` → `## Quick Start` → `Docs: https://github.com/switon-php/{name}/blob/main/docs/README.md` → `## License` (`MIT.`).

Use `## Highlights`, not `**Key features:**`. Keep acronyms conventional (`HTTP`, `CLI`, `ORM`, …).

## `faq.md`

High-frequency only. Scenario groups; conclusion-first answers. No mini-guides.

## `design.md`

`# Design Rationale {#design-rationale}` — why the component exists, first standardized layer, what stays outside, trade-offs. `## See also {#see-also}`. Not a source walkthrough.

## Trim by default

Catalogs, inventories, troubleshooting trees, volatile default assembly strings, brittle runtime snapshots. Prefer stable concepts and placeholders for volatile values.

Unless scoped otherwise, doc optimization converges toward `packages/log/docs/` split, weight, and link style; this file wins on conflict.
