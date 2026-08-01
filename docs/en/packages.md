---
description: Browse Switon packages with short summaries and direct links to the owner docs.
---

# Packages {#packages}

## Core Runtime {#core-runtime}

- [Core](https://github.com/switon-php/core/blob/main/docs/README.md) — use the shared contracts, attributes, and small utilities behind the rest of `Switon`
- [Principal](https://github.com/switon-php/principal/blob/main/docs/README.md) — keep current identity and tenant as normalized runtime state
- [Kernel](https://github.com/switon-php/kernel/blob/main/docs/README.md) — start HTTP apps, CLI apps, workers, and custom runtimes through one bootstrap path
- [DI](https://github.com/switon-php/di/blob/main/docs/README.md) — use property-based injection, autowiring, and named slots
- [Context](https://github.com/switon-php/context/blob/main/docs/README.md) — keep mutable state request- or coroutine-local instead of on shared services
- [Event](https://github.com/switon-php/event/blob/main/docs/README.md) — dispatch in-process events with attribute-based listeners
- [Invocation](https://github.com/switon-php/invocation/blob/main/docs/README.md) — define method-level guard and interceptor contracts
- [Invoker](https://github.com/switon-php/invoker/blob/main/docs/README.md) — call known instance methods without hand-building every argument
- [ID](https://github.com/switon-php/id/blob/main/docs/README.md) — choose ID generators for different sorting and storage needs
- [Pool](https://github.com/switon-php/pool/blob/main/docs/README.md) — manage reusable borrow/return access for shared resources
- [Sync](https://github.com/switon-php/sync/blob/main/docs/README.md) — use in-process coordination primitives like locks and waiting
- [Composer Extra](https://github.com/switon-php/composer-extra/blob/main/docs/README.md) — read installed package `extra` metadata at runtime

## Web & Delivery {#web-and-delivery}

- [HTTP](https://github.com/switon-php/http/blob/main/docs/README.md) — build controller-style endpoints on one request/response model
- [Router](https://github.com/switon-php/router/blob/main/docs/README.md) — use attribute-based HTTP routing with predictable path composition
- [View](https://github.com/switon-php/view/blob/main/docs/README.md) — render HTML pages with `Controller::Action` template resolution
- [Renderer](https://github.com/switon-php/renderer/blob/main/docs/README.md) — render templates for pages, fragments, mail, and UI pieces
- [Session](https://github.com/switon-php/session/blob/main/docs/README.md) — keep small user-scoped state across HTTP requests
- [Authorization](https://github.com/switon-php/authz/blob/main/docs/README.md) — keep access rules close to controllers and services
- [Dict](https://github.com/switon-php/dict/blob/main/docs/README.md) — standardize controlled value sets across action parameters, validation, and UI options
- [JWT](https://github.com/switon-php/jwt/blob/main/docs/README.md) — issue and validate HMAC JWTs
- [Throttle](https://github.com/switon-php/throttle/blob/main/docs/README.md) — apply Redis-backed rate limiting to HTTP actions and custom workloads
- [HTTP Cache](https://github.com/switon-php/http-cache/blob/main/docs/README.md) — cache full controller responses with `ETag` and cache headers
- [I18n](https://github.com/switon-php/i18n/blob/main/docs/README.md) — add translation, locale fallback, and request-scoped locale state
- [OpenAPI](https://github.com/switon-php/openapi/blob/main/docs/README.md) — keep API docs close to controller routes
- [Captcha](https://github.com/switon-php/captcha/blob/main/docs/README.md) — generate and verify sessionless CAPTCHA for APIs and SPAs

## Data & Storage {#data-and-storage}

- [DB](https://github.com/switon-php/db/blob/main/docs/README.md) — run direct SQL with safe defaults and explicit transaction pinning
- [Query](https://github.com/switon-php/query/blob/main/docs/README.md) — assemble filters, sorting, and pagination at runtime
- [ORM](https://github.com/switon-php/orm/blob/main/docs/README.md) — work with typed entities and repository-led application code
- [ORM Codegen](https://github.com/switon-php/orm-codegen/blob/main/docs/README.md) — scaffold ORM code from existing database tables
- [Sharding](https://github.com/switon-php/sharding/blob/main/docs/README.md) — split data across databases or tables while keeping repo/entity code mostly normal
- [Cache](https://github.com/switon-php/cache/blob/main/docs/README.md) — add Redis-backed caching with read-through defaults
- [Redis](https://github.com/switon-php/redis/blob/main/docs/README.md) — use Redis with pooling, transient connections, and lifecycle events
- [Storage](https://github.com/switon-php/storage/blob/main/docs/README.md) — store uploads, exports, generated files, and other local blobs
- [Crypto](https://github.com/switon-php/crypto/blob/main/docs/README.md) — handle symmetric encryption, keys, and rotation

## Execution & Integration {#execution-and-integration}

- [CLI](https://github.com/switon-php/cli/blob/main/docs/README.md) — write app commands as plain PHP classes
- [Schedule](https://github.com/switon-php/schedule/blob/main/docs/README.md) — run recurring tasks inside `Switon` instead of wiring many cron entrypoints
- [Queue](https://github.com/switon-php/queue/blob/main/docs/README.md) — buffer durable Redis-backed jobs between producers and workers
- [Executor](https://github.com/switon-php/executor/blob/main/docs/README.md) — trigger the same runner-style work from different parts of the app
- [RPC](https://github.com/switon-php/rpc/blob/main/docs/README.md) — do queue-backed request/reply when caller and worker already share PHP types
- [HTTP Client](https://github.com/switon-php/http-client/blob/main/docs/README.md) — call upstream APIs with a JSON-first default path
- [Mail](https://github.com/switon-php/mail/blob/main/docs/README.md) — send SMTP mail with a fluent message model

## Quality & Diagnostics {#quality-and-diagnostics}

- [Validation](https://github.com/switon-php/validation/blob/main/docs/README.md) — validate with attribute rules, normalization, and locale-aware messages
- [Log](https://github.com/switon-php/log/blob/main/docs/README.md) — use PSR-3 logging with structured context and zero-config defaults
- [Inspector](https://github.com/switon-php/inspector/blob/main/docs/README.md) — inspect requests in running `Switon` applications
- [Testing](https://github.com/switon-php/testing/blob/main/docs/README.md) — run package tests against a realistic DI container
- [Beacon](https://github.com/switon-php/beacon/blob/main/docs/README.md) — install app-facing AI rules and skills through one `Switon` package
