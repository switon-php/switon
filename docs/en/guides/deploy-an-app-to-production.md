# Deploy an app to production {#deploy-an-app-to-production}

Use this guide when one working Switon app now needs a stable production shape across runtime, config, persistent resources, and worker processes without turning container platforms, blue-green rollout, or fleet automation into the first answer.
It starts after the app already works locally. The job here is to make the runtime shape explicit, not to debug the first request path.

## Choose the right production boundary {#production-boundary}

Start with the boundary that actually changes between local work and production operation.

| Boundary | Best when | Keep in mind |
|---|---|---|
| [Kernel](../kernel/README.md) | the real problem is where env, YAML, and app metadata come from | system env wins over `.env.local` and `.env`; `switon.yml` stays the primary config surface |
| [HTTP Server](../http/server.md#server-deployment) | you must choose the HTTP adapter, proxy shape, and restart policy | `type: php` is development-only; `swoole` and `fpm` are the production choices |
| [Storage](../storage/README.md) | uploads, generated files, or blobs must survive deploys or cleanup | `@runtime` is fine for runtime-owned files, not for durable user data |
| [Redis](../redis/README.md) | queues, locks, cache, or session traffic now need real backing-service policy | one shared client is enough first; split named clients only when workloads truly differ |
| [Schedule](../schedule/README.md) | recurring work exists and now must run reliably in production | the HTTP server does **not** start scheduled work for you |
| [Queue](../queue/README.md) / [RPC](../rpc/README.md) | background or request/reply workers must run outside the request lifecycle | keep worker loops as separate operator-managed processes |
| [Log](../log/README.md) / [Inspector](../inspector/README.md) | you need production signal without leaving development debugging turned on | keep one stable log target first; use Inspector only for controlled debugging |

Common shape: **Kernel** for config, **HTTP** for requests, **Storage** for durable files, **Redis** for shared backing services, separate **Schedule/Queue/RPC** processes, and **Log** as the first operational signal.

## Recommended build order {#build-order}

1. Start only after one real app path already works.
2. Keep one clear config story: `switon.yml`, optional `config/*.php`, and system env.
3. Set app metadata and public route shape explicitly.
4. Choose the HTTP adapter before proxy tuning.
5. Put durable files on durable paths.
6. Run HTTP, scheduler, and workers as separate processes.
7. Choose one log target and one debug posture.
8. Verify routes and operator surfaces before public traffic.

## Minimal wiring {#minimal-wiring}

Use short snippets only to show the seam between configuration, process shape, and production-safe defaults.

Application-level values such as app id, mounted paths, and Redis URIs below are illustrative. The Switon framework symbols, config keys, and command surfaces are the stable part.

### Keep one production-leaning app and HTTP baseline {#wiring-app-http}

Start with a small `switon.yml` that makes production intent explicit:

```yaml
# switon.yml (project root)
Switon\Core\AppInterface:
  id: myapp
  name: My Application
  env: ${APP_ENV:-prod}
  debug: false
  timezone: ${APP_TIMEZONE:-UTC}

Switon\Routing\RouterInterface:
  prefix: /api

Switon\Http\ServerOptions:
  type: swoole
  host: 0.0.0.0
  port: 9501
  settings:
    worker_num: 8
    max_request: 100000
```

Use `type: fpm` instead when your deployment is built around PHP-FPM.
The `swoole` block above is one concrete production example, not a required default for every app.
If you run Swoole behind Nginx, reuse the proxy boundary from [Server & Deployment — Nginx Reverse Proxy](../http/server.md#nginx) instead of inventing a parallel HTTP shape.

### Keep durable files and Redis clients explicit {#wiring-storage-redis}

Move files and shared backing services onto production-owned paths and URIs:

```yaml
# switon.yml
Switon\Storage\BlobStoreInterface:
  root: '<mounted-path>/uploads'

Switon\Redis\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: redis://127.0.0.1:6379/0
  queue:
    uri: redis://127.0.0.1:6379/2?pool_size=32

Psr\Log\LoggerInterface:
  level: info
  appenders:
    file: Switon\Logging\Appender\FileAppender
```

If you are deploying into a container-first environment, switch the primary log destination to `Switon\Logging\Appender\StdoutAppender` instead of keeping host-file assumptions.

### Verify the deploy with the same public entry surfaces {#wiring-verify}

Before real traffic, separate **one-shot checks** from **long-lived worker processes**.
That keeps the first production debug loop smaller: verify config and public paths first, then start background processes.

Use one-shot checks from the app root to confirm the deployed shape:

```bash
bash bin/console router:list --json
bash bin/console router:prefix
bash bin/console schedule:list
```

If the app uses recurring tasks and you want one direct proof before real time-based execution starts, run one task explicitly with the derived name from `schedule:list`:

```bash
bash bin/console schedule:task <name>
```

Start long-lived processes only when the app actually uses them:

```bash
bash bin/console rpc:daemon
```

If your project does not provide `bin/console`, use the fallback entry shape from [CLI Quick Start](../cli/README.md#fallback-entry).
For queue workers, keep your chosen consumer loop on the same principle: run it as its own operator-managed process instead of assuming the HTTP runtime will start it.

## What to avoid first {#pitfalls}

- **Shipping the PHP built-in server to production** — `type: php` is for development and quick local testing only
- **Treating `SIGUSR1` as a full release reload** — config, `.env`, and vendor changes still require a full restart
- **Keeping durable uploads under `@runtime`** — that path matches runtime lifecycle, not long-term file ownership
- **Assuming HTTP starts Schedule, Queue, or RPC loops automatically** — each of those runtime channels still needs its own operator process shape
- **Using `schedule:run` as if it ignores expression timing** — use `schedule:task <name>` for direct proof and keep `schedule:run` for due tasks
- **Leaving production on development-style debugging** — keep `debug: false` and use Inspector only when debugging is explicitly controlled
- **Splitting every Redis workload before the boundary is real** — start with one stable client story, then add named clients when traffic patterns justify it
- **Designing a full platform before one host or one deploy path is trustworthy** — prove one readable production topology first

## Go deeper {#go-deeper}

- **To finish the first JSON request path before go-live detail matters:** [Build an API](build-an-api.md#build-an-api)
- **To finish the first controller-to-template page path before go-live detail matters:** [Build HTML pages](build-html-pages.md#build-html-pages)
- **To go deeper on env loading, config merge rules, and app metadata:** [Kernel](../kernel/README.md) · [Core Package](../core/README.md#app-metadata-vs-scene)
- **To choose reverse proxy, adapter tuning, and restart policy in detail:** [HTTP](../http/README.md) · [Server & Deployment](../http/server.md#server-deployment)
- **To keep recurring work in its own production scheduler path:** [Add recurring work](add-recurring-work.md#add-recurring-work) · [Schedule](../schedule/README.md)
- **To operate durable background workers and transport boundaries:** [Add durable background work](add-durable-background-work.md#add-durable-background-work) · [Queue](../queue/README.md)
- **To run request/reply workers as a separate daemon path:** [Add queue-backed request/reply](add-queue-backed-request-reply.md#add-queue-backed-request-reply) · [RPC](../rpc/README.md)
- **To keep uploads and generated files on the right lifecycle path:** [Storage](../storage/README.md)
- **To tune shared clients, pooling, and queue-specific Redis traffic:** [Redis](../redis/README.md)
- **To choose a safer production log baseline and controlled debugging posture:** [Log](../log/README.md) · [Inspector](../inspector/README.md)
