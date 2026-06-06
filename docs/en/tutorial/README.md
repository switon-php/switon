---
description: Step through one Switon tutorial path from first HTTP route to services, persistence, CLI reuse, and logging.
---

# Tutorial {#tutorial}

This tutorial builds one small task API from HTTP routes through services, persistence, CLI reuse, and logging.
It is aimed at developers who can read PHP and want a practical Switon baseline.

## Start here {#start-here}

Before chapter `01`, confirm that you can run one app locally:

- [Install Switon](../getting-started/install.md#install)
- [Environment setup](../getting-started/environment.md#environment)
- [First Hello World checklist](../getting-started/first-hello-world.md#first-hello-world)

Use this tutorial if your main question is: "What does a normal Switon application look like?"
Use the guides later when you already know the next concrete job and want the shortest path to it.

If you already know web app basics and want a compressed version, use [Build an API](../guides/build-an-api.md#build-an-api).
If you want methodology first, start with [Core concepts](../concepts/README.md#switon-fundamentals).

## Main path {#main-path}

The main path evolves a single task manager API.

1. [01 — HTTP endpoints](01-http.md#01-http) — `HTTP`, `Router`, controllers, JSON responses
2. [02 — Clean input](02-input.md#02-input) — request input objects, body/query binding, `Validation`
3. [03 — Service boundary](03-service.md#03-service) — services, `DI`, controller boundaries
4. [04 — Stored data](04-data.md#04-data) — `ORM`, entity and repository roles, explicit persistence
5. [05 — CLI reuse](05-cli.md#05-cli) — console commands on the same service layer
6. [06 — Logging and failures](06-log.md#06-log) — `Log`, failure visibility, basic troubleshooting habits

For a first pass, finish `01` through `04` before moving on to `05`, `06`, or the side paths.

## Side paths {#side-paths}

After the main path, use side paths only if needed:

- [Timed cleanup](timed-cleanup.md#timed-cleanup) — run recurring cleanup work with `Schedule` after the CLI and service boundaries are clear
- [Faster list](faster-list.md#faster-list) — cache a read-heavy task list with `Cache` after stored data already works

These side paths are optional and independent.
