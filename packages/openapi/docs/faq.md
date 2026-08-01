# OpenAPI FAQ {#openapi-faq}
Short answers for the most common follow-up questions.

> **Prerequisites:** [OpenAPI Package](README.md)

## Getting Started {#getting-started}

### Where do I edit after `open-api:init`? {#edit-after-init}

Edit `openapi/openapi.yml`, `openapi/components/*.yml`, and `openapi/controllers/*.yml`.

### Why is `operationId` written as `user::index`? {#operationid-user-index}

That is the Switon handler-id shape. It keeps route docs, handler naming, and lint expectations aligned.

## Sync And Export {#sync-and-export}

### Why did sync write `controllers/user.yml`? {#wrote-controller-file}

Because the file name comes from the `operationId` prefix before `::`, not from the controller class name.

### Why did sync create `components/entity.user.yml`? {#created-entity-file}

That file is the expected shared entity schema for ids that start with `user::...`.

### Why was my `.yaml` file ignored? {#why-yaml-ignored}

The loader reads only `.yml`.

### Why did sync skip my route? {#why-sync-skipped-route}

`open-api:sync` scans only controller actions with class-level `#[RequestMapping]`, and it skips `#[ViewMapping]` and wildcard `*` verbs.

### What does `--deref` resolve? {#deref-resolve}

Only local `#/...` refs inside the merged document.

### Why did export keep or drop `components`? {#export-components}

`--deref` drops top-level `components` by default; add `--keep-components` if you want them preserved.

### What should downstream tools read? {#downstream-tools}

Usually `openapi/dist/openapi.json` (or whatever path you pass through `--out`).

## Lint {#lint}

### What fails lint as an error? {#fails-lint-error}

Duplicate `operationId` values and broken local `$ref` pointers.

### Why did lint warn about my response? {#lint-response-warning}

Warnings cover weak but still readable spec structure, including missing responses, missing `operationId`, missing path parameters, and `entity.*` schema hints. Use `--strict` if warnings should fail CI.

### When should I use `--strict`? {#use-strict}

When warnings should fail CI too.
