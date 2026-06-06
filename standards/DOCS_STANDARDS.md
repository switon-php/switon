# Documentation Standards

Output: `docs/en/**/*.md`. Scaffolding workflow: `DOCS_PLAYBOOK.md`.

## Why

- Docs build a **mental model** for adoption — capability lists and source walks do not.
- Markdown is for readers integrating the framework; PHPDoc owns contracts and code tracing. Do not duplicate either role.
- Published docs must not invent identifiers — wrong names break trust for split-repo and translated readers alike.

## Hard rules

- Every machine-meaningful identifier must match code or an authoritative discovery command — do not invent classes, methods, aliases, defaults, config keys, or commands.
- Backtick all such identifiers outside fenced blocks.
- `docs/en/**` is published package-facing docs, not monorepo contributor docs. No links to repository source paths.
- PHPDoc owns contracts and tracing; Markdown owns adoption, mental model, and high-level mechanics.
- `docs/en/{name}/README.md` + `faq.md` + `design.md` = component set. `packages/{name}/README.md` = lightweight landing only.

## Locales

- **Facts:** `docs/en/**` is source of truth for behavior.
- **Prose:** other locales are independently written natural language — same facts, not sentence mirroring. Explicit subjects; consistent terminology; translation-safe wording.
- **Parity:** same doc set shape, fenced examples, stable `{#anchors}`, sibling cross-links.
- **EN fixes via zh-CN only:** while editing `docs/zh-CN/**`, fix wrong/misleading matching `docs/en/**` in the **same change**. Do not churn `docs/en/**` otherwise or fan out every EN edit to all locales.

## Component guide (`docs/en/{name}/README.md`)

First screen: fit, value, trade-offs, boundaries — including what Switon standardizes first vs what stays in the app. Sections: `**Key features:**`, `## Installation {#installation}`, runnable Quick Start, `## Core Concepts {#core-concepts}`, `## Next {#next}`.

- Explain each concept once; link instead of repeat.
- Progressive disclosure: happy path first.
- Examples: short, runnable snippets; DI not `$container->get()` / `make()` in app examples.
- Config sections: capability-first; `config:options` targets interface FQCN when one exists; discovery commands at end.
- User-facing config examples use `switon.yml` unless PHP-only/runtime-only.
- CLI examples use installed-app entrypoints.
- No manual `**Contents:**`; no option catalog, source walk, extension manual, or migration unless essential.

## Package landing (`packages/{name}/README.md`)

`# Switon {Display Name} Package` → badges (CI + PHP 8.3+) → summary → `## Highlights` → `## Installation` → `## Quick Start` → docs URL → `## License` (`MIT.`).

Use `## Highlights`, not `**Key features:**`. Keep acronyms conventional (`HTTP`, `CLI`, `ORM`, …).

## `faq.md`

High-frequency only. Scenario groups; conclusion-first answers. No mini-guides.

## `design.md`

`# Design Rationale {#design-rationale}` — why the component exists, first standardized layer, what stays outside, trade-offs. `## See also {#see-also}`. Not a source walkthrough.

## Trim by default

Catalogs, inventories, troubleshooting trees, volatile default assembly strings, brittle runtime snapshots. Prefer stable concepts and placeholders for volatile values.

Unless scoped otherwise, doc optimization converges toward `docs/en/log/` split, weight, and link style; this file wins on conflict.
