---
description: Browse Switon packages with short summaries and direct links to the owner docs.
---

# Packages {#packages}

## Core Runtime {#core-runtime}

- [Core](core/README.md) — use the shared contracts, attributes, and small utilities behind the rest of `Switon`
- [Principal](principal/README.md) — keep current identity and tenant as normalized runtime state
- [Kernel](kernel/README.md) — start HTTP apps, CLI apps, workers, and custom runtimes through one bootstrap path
- [DI](di/README.md) — use property-based injection, autowiring, and named slots
- [Context](context/README.md) — keep mutable state request- or coroutine-local instead of on shared services
- [Event](event/README.md) — dispatch in-process events with attribute-based listeners
- [Invocation](invocation/README.md) — define method-level guard and interceptor contracts
- [Invoker](invoker/README.md) — call known instance methods without hand-building every argument
- [ID](id/README.md) — choose ID generators for different sorting and storage needs
- [Pool](pool/README.md) — manage reusable borrow/return access for shared resources
- [Sync](sync/README.md) — use in-process coordination primitives like locks and waiting
- [Composer Extra](composer-extra/README.md) — read installed package `extra` metadata at runtime

## Web & Delivery {#web-and-delivery}

- [HTTP](http/README.md) — build controller-style endpoints on one request/response model
- [Router](router/README.md) — use attribute-based HTTP routing with predictable path composition
- [View](view/README.md) — render HTML pages with `Controller::Action` template resolution
- [Renderer](renderer/README.md) — render templates for pages, fragments, mail, and UI pieces
- [Session](session/README.md) — keep small user-scoped state across HTTP requests
- [Authorization](authz/README.md) — keep access rules close to controllers and services
- [Dict](dict/README.md) — standardize controlled value sets across action parameters, validation, and UI options
- [JWT](jwt/README.md) — issue and validate HMAC JWTs
- [Throttle](throttle/README.md) — apply Redis-backed rate limiting to HTTP actions and custom workloads
- [HTTP Cache](http-cache/README.md) — cache full controller responses with `ETag` and cache headers
- [I18n](i18n/README.md) — add translation, locale fallback, and request-scoped locale state
- [OpenAPI](openapi/README.md) — keep API docs close to controller routes
- [Captcha](captcha/README.md) — generate and verify sessionless CAPTCHA for APIs and SPAs

## Data & Storage {#data-and-storage}

- [DB](db/README.md) — run direct SQL with safe defaults and explicit transaction pinning
- [Query](query/README.md) — assemble filters, sorting, and pagination at runtime
- [ORM](orm/README.md) — work with typed entities and repository-led application code
- [ORM Codegen](orm-codegen/README.md) — scaffold ORM code from existing database tables
- [Sharding](sharding/README.md) — split data across databases or tables while keeping repo/entity code mostly normal
- [Cache](cache/README.md) — add Redis-backed caching with read-through defaults
- [Redis](redis/README.md) — use Redis with pooling, transient connections, and lifecycle events
- [Storage](storage/README.md) — store uploads, exports, generated files, and other local blobs
- [Crypto](crypto/README.md) — handle symmetric encryption, keys, and rotation

## Execution & Integration {#execution-and-integration}

- [CLI](cli/README.md) — write app commands as plain PHP classes
- [Schedule](schedule/README.md) — run recurring tasks inside `Switon` instead of wiring many cron entrypoints
- [Queue](queue/README.md) — buffer durable Redis-backed jobs between producers and workers
- [Executor](executor/README.md) — trigger the same runner-style work from different parts of the app
- [RPC](rpc/README.md) — do queue-backed request/reply when caller and worker already share PHP types
- [HTTP Client](http-client/README.md) — call upstream APIs with a JSON-first default path
- [Mail](mail/README.md) — send SMTP mail with a fluent message model

## Quality & Diagnostics {#quality-and-diagnostics}

- [Validation](validation/README.md) — validate with attribute rules, normalization, and locale-aware messages
- [Log](log/README.md) — use PSR-3 logging with structured context and zero-config defaults
- [Inspector](inspector/README.md) — inspect requests in running `Switon` applications
- [Testing](testing/README.md) — run package tests against a realistic DI container
- [Beacon](beacon/README.md) — install app-facing AI rules and skills through one `Switon` package
