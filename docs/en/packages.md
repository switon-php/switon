---
description: Browse Switon packages with short summaries and direct links to the owner docs.
---

# Packages {#packages}

## Core Runtime {#core-runtime}

- [Core](../../packages/core/docs/README.md) — use the shared contracts, attributes, and small utilities behind the rest of `Switon`
- [Principal](../../packages/principal/docs/README.md) — keep current identity and tenant as normalized runtime state
- [Kernel](../../packages/kernel/docs/README.md) — start HTTP apps, CLI apps, workers, and custom runtimes through one bootstrap path
- [DI](../../packages/di/docs/README.md) — use property-based injection, autowiring, and named slots
- [Context](../../packages/context/docs/README.md) — keep mutable state request- or coroutine-local instead of on shared services
- [Event](../../packages/event/docs/README.md) — dispatch in-process events with attribute-based listeners
- [Invocation](../../packages/invocation/docs/README.md) — define method-level guard and interceptor contracts
- [Invoker](../../packages/invoker/docs/README.md) — call known instance methods without hand-building every argument
- [ID](../../packages/id/docs/README.md) — choose ID generators for different sorting and storage needs
- [Pool](../../packages/pool/docs/README.md) — manage reusable borrow/return access for shared resources
- [Sync](../../packages/sync/docs/README.md) — use in-process coordination primitives like locks and waiting
- [Composer Extra](../../packages/composer-extra/docs/README.md) — read installed package `extra` metadata at runtime

## Web & Delivery {#web-and-delivery}

- [HTTP](../../packages/http/docs/README.md) — build controller-style endpoints on one request/response model
- [Router](../../packages/router/docs/README.md) — use attribute-based HTTP routing with predictable path composition
- [View](../../packages/view/docs/README.md) — render HTML pages with `Controller::Action` template resolution
- [Renderer](../../packages/renderer/docs/README.md) — render templates for pages, fragments, mail, and UI pieces
- [Session](../../packages/session/docs/README.md) — keep small user-scoped state across HTTP requests
- [Authorization](../../packages/authz/docs/README.md) — keep access rules close to controllers and services
- [Dict](../../packages/dict/docs/README.md) — standardize controlled value sets across action parameters, validation, and UI options
- [JWT](../../packages/jwt/docs/README.md) — issue and validate HMAC JWTs
- [Throttle](../../packages/throttle/docs/README.md) — apply Redis-backed rate limiting to HTTP actions and custom workloads
- [HTTP Cache](../../packages/http-cache/docs/README.md) — cache full controller responses with `ETag` and cache headers
- [I18n](../../packages/i18n/docs/README.md) — add translation, locale fallback, and request-scoped locale state
- [OpenAPI](../../packages/openapi/docs/README.md) — keep API docs close to controller routes
- [Captcha](../../packages/captcha/docs/README.md) — generate and verify sessionless CAPTCHA for APIs and SPAs

## Data & Storage {#data-and-storage}

- [DB](../../packages/db/docs/README.md) — run direct SQL with safe defaults and explicit transaction pinning
- [Query](../../packages/query/docs/README.md) — assemble filters, sorting, and pagination at runtime
- [ORM](../../packages/orm/docs/README.md) — work with typed entities and repository-led application code
- [ORM Codegen](../../packages/orm-codegen/docs/README.md) — scaffold ORM code from existing database tables
- [Sharding](../../packages/sharding/docs/README.md) — split data across databases or tables while keeping repo/entity code mostly normal
- [Cache](../../packages/cache/docs/README.md) — add Redis-backed caching with read-through defaults
- [Redis](../../packages/redis/docs/README.md) — use Redis with pooling, transient connections, and lifecycle events
- [Storage](../../packages/storage/docs/README.md) — store uploads, exports, generated files, and other local blobs
- [Crypto](../../packages/crypto/docs/README.md) — handle symmetric encryption, keys, and rotation

## Execution & Integration {#execution-and-integration}

- [CLI](../../packages/cli/docs/README.md) — write app commands as plain PHP classes
- [Schedule](../../packages/schedule/docs/README.md) — run recurring tasks inside `Switon` instead of wiring many cron entrypoints
- [Queue](../../packages/queue/docs/README.md) — buffer durable Redis-backed jobs between producers and workers
- [Executor](../../packages/executor/docs/README.md) — trigger the same runner-style work from different parts of the app
- [RPC](../../packages/rpc/docs/README.md) — do queue-backed request/reply when caller and worker already share PHP types
- [HTTP Client](../../packages/http-client/docs/README.md) — call upstream APIs with a JSON-first default path
- [Mail](../../packages/mail/docs/README.md) — send SMTP mail with a fluent message model

## Quality & Diagnostics {#quality-and-diagnostics}

- [Validation](../../packages/validation/docs/README.md) — validate with attribute rules, normalization, and locale-aware messages
- [Log](../../packages/log/docs/README.md) — use PSR-3 logging with structured context and zero-config defaults
- [Inspector](../../packages/inspector/docs/README.md) — inspect requests in running `Switon` applications
- [Testing](../../packages/testing/docs/README.md) — run package tests against a realistic DI container
- [Beacon](../../packages/beacon/docs/README.md) — install app-facing AI rules and skills through one `Switon` package
