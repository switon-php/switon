# Inspector Package {#inspector-package}

`switon/inspector` gives Switon apps one request-scoped inspection boundary: one link per request, one stored payload, and one UI that can render the same data as HTML, JSON, text, or raw JSON.

> **Dev-only by default.** Inspector exposes configuration snapshots, resolved services, request data, and collector output. It auto-enables in `dev` and `test`; enable it in other environments only after you accept that exposure risk.

**Key features:**

- **One link per inspected request:** the `X-Inspector-Link` response header points to the stored payload
- **Collector composition:** built-in, package, and app-registered collectors merge into one payload
- **Four output views:** the same payload renders as `html`, `json`, `txt`, or `raw`
- **Backend switch by TTL:** `ttl > 0` writes to Redis (`base64(gzip(json))`); `ttl = 0` writes gzip JSON under `@runtime/inspector/{key}.zip`
- **Tooling hooks:** `Switon\Inspector\Event\InspectorLinkGenerated` fires per link; `bash bin/console inspector:watch` tails Redis pub/sub broadcasts

---

## Installation {#installation}

**Requirements:** PHP 8.3+, `switon/http`, `switon/redis`, `switon/kernel`, `switon/log`, `switon/router`, `switon/event`, `switon/di`

```bash
composer require --dev switon/inspector
```

---

## Quick Start {#quick-start}

Run the app in `dev` or `test`, make one request, and open the returned inspector URL.

```bash
curl -i http://<host>:<port>/api/users
```

A successful response includes a header like this:

```text
X-Inspector-Link: http://<host>:<port>/?__inspector=<key>.html
```

In `dev`, you may also see:

```text
X-Inspector-Route: <Controller>::<action>
```

`X-Inspector-Route` stays a `dev`-only header unless `Switon\Inspector\Listener\RouteHeaderListener.enabled` is set to `true`.

If Redis is not available, set `Switon\Inspector\StorageInterface.ttl` to `0` first; that switches payload storage to `@runtime/inspector/{key}.zip` (gzip-compressed JSON).

Open the `.html` URL in a browser, or fetch other views directly:

```bash
curl "http://<host>:<port>/?__inspector=<key>.json"
curl "http://<host>:<port>/?__inspector=<key>.txt"
curl "http://<host>:<port>/?__inspector=<key>.raw"
```

---

## Core Concepts {#core-concepts}

### Request Lifecycle {#request-lifecycle}

Inspector follows the request lifecycle instead of a separate debug endpoint.

- `RequestBegin` creates the key and sets `X-Inspector-Link`
- collectors observe framework events during the request
- `RequestEnd` writes one stored payload
- `RenderListener` serves the payload as `html`, `json`, `txt`, or `raw`

If `tail` is enabled and the response content is an array, Inspector adds the current link under `inspector`. ApacheBench requests are skipped.

### Collector Composition {#collector-composition}

Collectors come from two places:

- package metadata in `extra.switon.collectors`
- app config under `Switon\Inspector\CollectorDiscoveryInterface.collectors`

Set a collector key to `null` to disable it. Packages can contribute tabs without hard-wiring them into the core package.

### Storage {#storage}

`Switon\Inspector\StorageInterface.ttl` selects the backend. `ttl > 0` writes Redis entries under `prefix + key` as `base64(gzip(json))` with TTL applied; `ttl = 0` writes `@runtime/inspector/{key}.zip` as gzip-compressed JSON. There is no automatic fallback between the two.

`broadcast = true` (Redis mode only) publishes generated links on the channel `__inspector:{app_id}:{ip}:{handler}`; `inspector:watch` subscribes to the same pattern.

### Output {#output}

The same stored payload renders as `html`, `json`, `txt`, or `raw`.

### Events {#events}

- `Switon\Inspector\Event\InspectorLinkGenerated` fires for every generated link and carries the URL plus storage key; use it when automation already lives in the event system.
- `bash bin/console inspector:watch` subscribes to `__inspector:{app_id}:{ip}:{handler}` and prints matching links. It can narrow output with `--handlerPrefix` and `--ip` (last-octet match when no dot is given) and works only when Redis mode is active.

---

## Configuration {#configuration}

Start with the defaults. Add config only when you need to control enablement, retention, collectors, or rendering.

- `Switon\Inspector\InspectorInterface.enabled` (`null`) auto-enables in `dev` and `test`; `false` keeps `Inspector::boot()` inert and registers no listeners.
- `Switon\Inspector\InspectorInterface.tail` (`true`) injects the link as `inspector` into array response bodies on stringify.
- `Switon\Inspector\Listener\RouteHeaderListener.enabled` (`null`) auto-enables the `X-Inspector-Route` header in `dev`.

```yaml
# switon.yml
Switon\Inspector\InspectorInterface:
  enabled: null
  tail: true

Switon\Inspector\StorageInterface:
  ttl: 3600
  prefix: cache:inspector:
  broadcast: true

Switon\Inspector\CollectorDiscoveryInterface:
  autoDiscover: true
  collectors:
    # logger: null
    # custom: App\Inspector\RequestTagCollector

Switon\Inspector\HtmlRendererInterface:
  # pageTemplate: '@switon.inspector.resources/templates/inspector.html'
  tabs:
    # custom: '@switon.inspector.resources/templates/<collector>-tab.js'

Switon\Inspector\Listener\RouteHeaderListener:
  enabled: null
```

### Collector Settings {#collector-settings}

- `autoDiscover` (`true`) reads `extra.switon.collectors` from installed packages
- `collectors` adds, overrides, or disables individual collector keys (`null` disables)

### Storage Settings {#storage-settings}

- `ttl` selects backend (`>0` Redis, `=0` filesystem); the default Redis path requires `switon/redis` to be available
- `prefix` sets the Redis key prefix
- `broadcast` publishes links on the Redis channel; ignored in filesystem mode

### Runtime Switches {#runtime-switches}

- `enabled` controls whether Inspector boots at all
- `tail` adds the current link to array responses when enabled
- `Switon\Inspector\Listener\RouteHeaderListener.enabled` controls `X-Inspector-Route`
- override `Inspector::onServerReady()` in a subclass for startup probes or diagnostics

### Rendering Settings {#rendering-settings}

- `pageTemplate` replaces the default HTML shell
- `tabs` maps collector keys to per-tab JS templates

---

## Option Discovery {#option-discovery}

Use `config:options` to inspect the current option surface.

```bash
bash bin/console config:options 'Switon\Inspector\InspectorInterface'
```

Query `StorageInterface`, `CollectorDiscoveryInterface`, or `HtmlRendererInterface` when you need their current surfaces.

---

## Keep in Mind {#keep-in-mind}

- Dev-only by default; do not treat Inspector output as safe-by-default production telemetry.
- `ttl > 0` means Redis, `ttl = 0` means filesystem; there is no automatic fallback.
- ApacheBench requests bypass Inspector entirely (no link, no stored payload).
- Set a discovered collector key to `null` under `collectors` to disable it without forking the providing package.
- `InspectorLinkGenerated` carries the URL and storage key only, not the full payload.

---

## Next {#next}

- **To answer common setup questions:** [FAQ](faq.md#inspector-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
