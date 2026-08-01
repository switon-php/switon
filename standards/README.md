# Switon standards

Use this file as the entrypoint for standards work.

- Before editing, classify the task here first.
- If you are doing one of the tasks below, you must read the mapped files before making changes.
- Do not start from sub-docs directly.
- `README.md` is the only file-level index; sub-docs define local rules only.
- `standards/*.md` is written AI-first, but should stay easy for a human to skim.
- Keep standards dense and actionable: preserve routing, constraints, boundaries, and commands that affect actions.
- Remove obvious explanations, repeated motivation, and step-by-step teaching of self-evident basics.

## AI doc shape

Assume a capable reader. Do not dumb down; do not repeat the same rule in multiple sections.

| Keep | Drop |
|------|------|
| task routing, hard constraints, non-obvious defaults | tutorials, step-by-step teaching of obvious basics |
| one short **Why** per doc (and per major section when trade-offs differ) | the same motivation copied into scope, checklist, and examples |
| Good/Bad examples for repo-specific or surprising rules | examples that only restate the line above |
| links to authoritative sub-docs | duplicate file catalogs when the task table already routes |

**Why stays.** It tells the reader which rule wins when constraints conflict. Keep it once at doc level; repeat only when a subsection has a different trade-off.

Need this in `AGENTS.md`? Send:

```
Append standards/README.md into the Standards section of AGENTS.md.
```

## Task → must read before editing

| Task | Must read |
|------|------|
| **Code** (write/edit framework code) | `CODE_STANDARDS.md` + `COMPONENT_STANDARDS.md` + `PHPDOC_STANDARDS.md` |
| **DI subsystem work** (`#[Autowired]`, factory, named services) | `CODE_STANDARDS.md` + `PHPDOC_STANDARDS.md` |
| **HTTP subsystem work** (request lifecycle, response, exception path) | `CODE_STANDARDS.md` + `COMPONENT_STANDARDS.md` + `PHPDOC_STANDARDS.md` + `HTTP_STANDARDS.md` |
| **Tests** (write/review package tests) | `TESTING_STANDARDS.md` |
| **PHPStan** (extensions, `phpstan.neon`, split-package analysis) | `PHPSTAN_STANDARDS.md` |
| **Test setup** (create `tests/` for a package) | `TEST_SETUP.md` + `TESTING_STANDARDS.md` |
| **Versioning / release strategy** (package releases, framework distribution releases, tags, release-time version records) | `VERSIONING_STANDARDS.md` |
| **Package metadata** (`composer.json` descriptions, keywords, support links, type, field order) | `PACKAGE_METADATA_STANDARDS.md` |
| **PHPDoc / @see / Guidance / Road-signs** | `PHPDOC_STANDARDS.md` |
| **Documentation** (write/edit Markdown docs) | `DOCS_STANDARDS.md` + **sps-docs** for `packages/{name}/docs/**` (component) and `docs/en/**` (narrative) |
| **App integration** (use/configure components in apps) | `packages/skeleton/AGENTS.md` — read `Switon package rules` only |
| **Contributing / PRs** | `CONTRIBUTING.md` + `GIT_COMMIT_STANDARDS.md` |

- Unless explicitly scoped otherwise, requests to optimize component docs default to converging toward the current `packages/log/docs/` doc split, weight control, and link style. If that baseline conflicts with `DOCS_STANDARDS.md`, `DOCS_STANDARDS.md` wins.
- In documentation tasks, unqualified component `README.md` defaults to `packages/{name}/docs/README.md`. `packages/{name}/README.md` is a separate lightweight package entry; do not apply component-guide rules to it by default.
- Component docs live in `packages/{name}/docs/**` (split-repo GitHub links). Narrative docs stay under `docs/en/**`. Do not rewrite examples to monorepo-only entrypoints just to make them runnable from this repository checkout.
- In documentation work, every code-like identifier must be verified against the codebase or an authoritative discovery command. Do not invent names, aliases, defaults, classes, methods, config keys, or commands.

## Docs

| File | Purpose |
|------|--------|
| `CODE_STANDARDS.md` | Coding rules (DI, interfaces, exceptions, visibility, etc.) |
| `COMPONENT_STANDARDS.md` | Component architecture, state/context boundaries, events, docs ownership |
| `HTTP_STANDARDS.md` | Stable HTTP authoring rules for lifecycle, response shaping, and exception flow |
| `TESTING_STANDARDS.md` | Test quality bar, naming, AAA, DI setup, and execution rules |
| `PHPSTAN_STANDARDS.md` | PHPStan extension-installer, `extra.phpstan`, monorepo vs split-repo analysis |
| `TEST_SETUP.md` | Package `tests/` scaffolding, required files, and layout conventions |
| `VERSIONING_STANDARDS.md` | Package versioning, framework distribution versioning, release tags, and release-time version records |
| `PACKAGE_METADATA_STANDARDS.md` | `composer.json` metadata quality, discoverability, support links, type, and field order |
| `PHPDOC_STANDARDS.md` | Source-level PHPDoc rules and navigation mesh |
| `DOCS_STANDARDS.md` | Markdown documentation scope, structure, and style |
| `DOCS_PLAYBOOK.md` | Repeatable workflow for rewriting `packages/{name}/docs/` component docs |
| `CONTRIBUTING.md` | Contributor workflow and submission checklist |
| `GIT_COMMIT_STANDARDS.md` | Commit message format, scope rules, and history quality bar |
