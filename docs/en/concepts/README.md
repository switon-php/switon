---
description: Learn the Switon application model, request flow, service placement, and runtime-safe boundaries.
---

# Switon fundamentals {#switon-fundamentals}

Read this page first when you want the Switon application model before you choose a task guide or open package docs one by one.

## Start here {#start-here}

- If you do not have a local runtime ready yet, start with [Install Switon](../getting-started/install.md#install).
- If your shell still lacks `php`, `composer`, or `git`, use [Environment setup](../getting-started/environment.md#environment) first.
- If recurring words such as `boundary`, `runtime`, `Context`, or `worker` slow you down, keep [Glossary for beginners and translators](glossary.md#glossary) open while you read.
- If you already know the task you want to finish, stop here and switch to [Guides](../guides/README.md#guides) instead.
- Otherwise, read the fundamentals below in order.

## Fundamentals {#fundamentals}

Read these pages in order when you want the shortest complete model:

1. **[Understand the Switon framework methodology](framework-methodology.md#framework-methodology)** — what Switon tries to standardize, and what it deliberately leaves to the application
2. **[Understand the application model](application-model.md#application-model)** — where startup begins, what `Kernel` owns, and how `switon.yml`, `.env`, and `config/*.php` relate
3. **[Understand modules and areas](modules.md#modules)** — how feature code groups under `app/Areas/<Area>/`, and when a feature should become a reusable module or package
4. **[Understand config and conventions](config-and-conventions.md#config-and-conventions)** — symbols such as `${APP_ENV:-prod}`, `#cache`, `@view`, and same-namespace `XxxInterface` -> `Xxx`
5. **[Understand the request lifecycle](request-lifecycle.md#request-lifecycle)** — how one HTTP request moves from transport to route, input binding, business code, and response output
6. **[Understand service boundaries](service-boundaries.md#service-boundaries)** — where controllers, services, lifecycle listeners, event listeners, commands, and workers should stop and hand off
7. **[Understand runtime and state](runtime-and-state.md#runtime-and-state)** — why singleton services stay safe, when `Context` exists, and where mutable request-scoped state belongs

## Concepts versus guides {#concepts-versus-guides}

Use concepts when the next problem is placement, vocabulary, or framework model. Use guides when the next problem is a concrete application task.

- **Concepts answer where something belongs** — for example whether code belongs in an area, service, listener, command, job, or package.
- **Guides answer what to do next** — for example build the first API path, add auth, persist data, or add durable background work.

- If both seem relevant, read only until the placement decision is clear.
- Then switch to the matching guide.
- Concepts should shorten task work, not delay it.

## App Shape {#fits-together}

The default Switon application model is small but opinionated:

1. `Kernel` boots env, config, app metadata, and services.
2. A runtime channel such as HTTP or CLI takes over.
3. Transport-facing code reaches a controller, command, or worker boundary.
4. Business work stays in services instead of spreading through transport handlers.
5. Cross-cutting reactions move to listeners when they should not stay inline.
6. Mutable request-scoped state stays out of shared service properties and goes through `Context` when needed.

- These concept pages come before package-by-package reading.
- They explain the framework rules that keep one app shape consistent across HTTP, CLI, events, and workers.
