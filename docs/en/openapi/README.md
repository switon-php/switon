# OpenAPI Package {#openapi-package}
`switon/openapi` keeps API documentation aligned with controller routes through a split YAML workflow, conservative sync from discovered routes, a merged export, and quality lint. Choose it when you want one source-aligned spec without rewriting hand-edited YAML; the package does not own client codegen or external bundling.

**Key features:**

- **Split YAML workflow:** `openapi.yml` for top-level state, `components/*.yml` for shared schemas, and `controllers/*.yml` for per-controller operations.
- **Route-driven sync:** `open-api:sync` appends only missing stubs from controllers with class-level `#[RequestMapping]`.
- **Stable `operationId`:** generated ids follow the Switon handler-id shape such as `user::index`.
- **Merged export:** `open-api:export` combines the fragments into one document.
- **Lint support:** `open-api:lint` reports duplicate ids, broken `#/...` refs, and common spec quality issues.

## Installation {#installation}

**Requirements:** PHP 8.3+, `ext-json`, `switon/core`, `switon/command`, `switon/http`, `switon/router`, `switon/yaml`

```bash
composer require switon/openapi
```

## Quick Start {#quick-start}

```bash
bash bin/console open-api:init
bash bin/console open-api:sync
bash bin/console open-api:export
bash bin/console open-api:lint
```

`open-api:init` scaffolds `openapi.yml`, `controllers/`, `components/`, and a package README under `@root/openapi`. By default it also runs one sync pass; use `--no-sync` for the scaffold only.

After init, edit the generated YAML files directly. The default export target is `{openapi-root}/dist/openapi.json` (typically `openapi/dist/openapi.json`).

Common variants:

- `open-api:sync --controller=... --action=... --tail` to narrow a sync pass.
- `open-api:export --out=... --json --deref --keep-components` for alternate export modes.
- `open-api:lint --strict` to fail CI on warnings too.

## Core Concepts {#core-concepts}

### Split Files {#split-files}

The package reads one root tree, usually `openapi/`. Only `.yml` files are read.

| Path | Role |
|---|---|
| `openapi/openapi.yml` | top-level document state such as `openapi`, `info`, and `servers` |
| `openapi/components/*.yml` | shared schemas and reusable components |
| `openapi/controllers/*.yml` | per-controller operations written or extended by sync |
| `openapi/dist/openapi.json` | merged export output (default) |
| `openapi/recommended.example.yml` | scaffold example copied by init, not merged |
| `openapi/components/entity.example.yml` | scaffold example copied by init and merged until you remove it |

### `operationId` {#operation-id}

`operationId` is expected to match the Switon handler-id shape.

- `App\Controller\UserController::indexAction` → `user::index`
- `App\Areas\Admin\Controller\AccountController::captchaAction` → `admin.account::captcha`

That keeps route docs, RBAC-style tooling, and lint expectations aligned.

### Sync Scope {#sync-scope}

`open-api:sync` reads controller routes, not the full router table.

- it scans only controllers with class-level `#[RequestMapping]`
- it skips `#[ViewMapping]`
- it skips wildcard `*` HTTP verbs
- it can add missing path parameters and JSON response schema stubs from the route pattern and action return type
- the controller YAML file name comes from the `operationId` prefix before `::`, not from the controller class name

### Sync, Export, And Lint {#sync-export-lint}

- `open-api:sync` discovers controller mappings and appends only missing stubs.
- `open-api:export` merges `openapi.yml`, then `components/*.yml`, then `controllers/*.yml`; each group is sorted by file name.
- `--deref` resolves local `#/...` refs only and drops top-level `components` unless `--keep-components` is set.
- `open-api:lint` reports duplicate `operationId` and broken local `#/...` refs as errors; weak structure surfaces as warnings; `--strict` fails on warnings too.

## Keep in Mind {#keep-in-mind}

- Sync is conservative; it skips files it cannot patch safely.
- `--deref` does not resolve external or non-local `$ref` values.
- `components/entity.example.yml` is a real input file until you replace or remove it.
- Call `Switon\OpenApi\DocumentBuilderInterface::build()` directly when you want the merged document as a PHP array.

## Next {#next}

- **To answer common questions:** [FAQ](faq.md#openapi-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
- **To align routes and controller mappings:** [HTTP Package](../http/README.md)
