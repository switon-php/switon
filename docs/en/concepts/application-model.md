# Understand the application model {#application-model}

One mental model for startup, configuration, app metadata, and runtime entrypoints before you focus on one feature.

## Terms at a glance {#terms}

If one term here feels new, keep [Glossary for beginners and translators](glossary.md#glossary) open while you read.

- **bootstrap** — the initialization phase that loads env, config, and services before runtime work begins
- **runtime** — the execution channel such as HTTP, CLI, schedule, or worker
- **area** — an application-local feature grouping, usually under `app/Areas/<Area>/`
- **module** — a coherent feature unit that may stay application-local or become package/plugin-owned
- **app metadata** — application identity and environment values such as id, env, debug, and timezone
- **scene** — the current runtime label such as `http`, `cli`, or `schedule`
- **service-definition map** — the effective DI/config definitions built from `switon.yml` and optional PHP config patches

## One mental model first {#mental-model}

Start with this framework shape:

1. **`Kernel` starts the app** and prepares the shared base.
2. **Environment and config are loaded** so the application has one effective definition map.
3. **The container resolves services** and package providers finish bootstrap work.
4. **A runtime channel takes over** — for example HTTP serves requests, CLI runs commands, and worker processes consume background work.
5. **Application code handles business work through services**, not through bootstrap code or transport-specific globals.

That model explains why the same service layer can be reused from a controller, command, scheduled task, or worker process.

## Startup and configuration {#startup-and-configuration}

In normal application startup, `Kernel` is the boundary that prepares env, config, DI, and fatal-error handling before runtime-specific logic continues.

Two rules matter most first:

- **`switon.yml` is the primary application config surface**
- **`config/*.php` is the supplement when YAML is not enough**

Environment values are loaded before config is read, so `${VAR}` interpolation is ready by the time application definitions are built.
System environment variables stay higher priority than `.env.local` and `.env`.

This gives you one stable mental model:

- env decides deploy-specific values
- `switon.yml` describes the normal application shape
- optional PHP config handles values YAML cannot express cleanly, such as computed values, conditional registration, or small programmatic patches

For the authoritative details, use [Kernel](../../packages/kernel/docs/README.md) and [Core Package](../../packages/core/docs/README.md#core-concepts).

## App metadata and runtime scene {#app-runtime-scene}

Switon keeps **application identity** separate from **current runtime channel**.

- **`Switon\Core\AppInterface`** is for app metadata such as id, env, debug, and timezone
- **`Switon\Core\SceneManagerInterface`** is for the current runtime scene such as `http`, `cli`, or `schedule`

That separation is useful because most application services need only app metadata, not the current runtime label.
When code really must react to the runtime channel, `SceneManagerInterface` is the narrower contract.

A practical default:

- reach for `AppInterface` in normal application code
- reach for `SceneManagerInterface` only when scene-aware behavior is actually required

See [Core Package](../../packages/core/docs/README.md#app-metadata-vs-scene) for the owner doc.

## Providers versus application code {#providers-application-code}

One common source of confusion is mixing bootstrap wiring with normal application code.

Use this split:

- **Service providers** exist for package wiring and bootstrap hooks
- **`switon.yml`** exists for application overrides and stable configuration
- **application services** use `#[Autowired]` and normal package contracts

That means most app code should not start from provider classes, static container access, or manual bootstrap hooks.
If the class is normal application behavior, the first question is usually not "which provider should I edit?" but "which service boundary should own this?"

See [Core](../../packages/core/docs/README.md), [DI](../../packages/di/docs/README.md), and [Kernel — service providers](../../packages/kernel/docs/README.md#service-providers).

## Typical application shape {#typical-application-shape}

A small Switon app usually reads like this:

1. **Bootstrap** — `Kernel` loads env, config, and services
2. **Runtime entry** — HTTP, CLI, scheduler, or worker path takes control
3. **Transport boundary** — controller, command, or worker entry receives input
4. **Business boundary** — a service performs the real application work
5. **Infrastructure boundary** — persistence, cache, mail, queue, or storage is used where the feature actually needs it

The important point is not the exact package list. The important point is that runtime entrypoints stay thin and business code stays reusable.

When a feature grows beyond a flat `app/` tree, group application-owned code by area before inventing a reusable package. See [Understand modules and areas](modules.md#modules) for the placement model.
