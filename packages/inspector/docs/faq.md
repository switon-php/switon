# Inspector FAQ {#inspector-faq}

Short answers for setup, configuration, and common inspector issues.

> **Prerequisites:** [Inspector Package](README.md)

## Getting Started {#getting-started}

### How do I verify Inspector is working? {#verify-working}

Make one request in `dev` or `test`, then open the `X-Inspector-Link` URL. See [Quick Start](README.md#quick-start).

### Can I use Inspector without Redis? {#without-redis}

Yes. Set `Switon\Inspector\StorageInterface.ttl` to `0` to switch to filesystem storage. See
[Storage Settings](README.md#storage-settings).

The filesystem payload is gzip-compressed JSON stored under `@runtime/inspector/{key}.zip`.

### How do I read the payload without the HTML page? {#raw-data}

Use the same key with `.json`, `.txt`, or `.raw`. See [Storage](README.md#storage) and [Output](README.md#output).

### How do I watch Inspector links in the terminal? {#watch-terminal}

Run `bash bin/console inspector:watch`. It listens to Redis pub/sub, so it works only in Redis mode. See
[Events](README.md#events).

You can filter by handler prefix and IP suffix.

## Configuration {#configuration}

### How do I force-enable Inspector outside `dev` and `test`? {#force-enable}

Set `Switon\Inspector\InspectorInterface.enabled` to `true`. See [Runtime Switches](README.md#runtime-switches).

### How do I add the inspector URL into response content? {#tail}

Enable `Switon\Inspector\InspectorInterface.tail`; when the response content is an array, Inspector adds an
`inspector` field during response stringification. See [Runtime Switches](README.md#runtime-switches).

### How do I keep data longer or shorter? {#retention}

Adjust `Switon\Inspector\StorageInterface.ttl`. See [Storage Settings](README.md#storage-settings).

Redis payloads are stored as `base64(gzip(json))`; filesystem payloads are gzip only.

### How do I disable one collector? {#disable-collector}

Set that key to `null` under `Switon\Inspector\CollectorDiscoveryInterface.collectors`. See
[Collector Settings](README.md#collector-settings).

### How do I customize the Inspector UI? {#template-customization}

Override `Switon\Inspector\HtmlRendererInterface.pageTemplate` for the page shell or `tabs` for collector-specific tab
scripts. See [Rendering Settings](README.md#rendering-settings).

### How do I inspect the current options in my version? {#discover-options}

Run `bash bin/console config:options 'Switon\Inspector\InspectorInterface'`. If you need storage or collector options,
query those interfaces directly.

### Why is `X-Inspector-Route` missing in `test`? {#route-header-test}

`X-Inspector-Route` is a separate listener. It auto-enables in `dev` by default, not in `test`.

## Collectors and Output {#collectors-and-output}

### What data is built in by default? {#default-data}

The package ships common panels for runtime info, dependencies, events, log entries, files, app metadata, and provider
discovery. See [Collector Composition](README.md#collector-composition).

### Why are SQL, router, or render tabs missing? {#missing-tabs}

Those tabs appear only when another package or your app contributes the matching collector. See
[Collector Composition](README.md#collector-composition).

### Can I add custom collectors? {#custom-collector}

Yes. Register them under `Switon\Inspector\CollectorDiscoveryInterface.collectors`. See
[Collector Settings](README.md#collector-settings).

### Why does my custom collector render as a generic JSON tab? {#generic-tab}

No collector-specific tab script is configured for that key, so Inspector uses the generic fallback. See
[Rendering Settings](README.md#rendering-settings).

## Troubleshooting {#troubleshooting}

### `X-Inspector-Link` is missing {#missing-header}

Check whether Inspector is enabled in the current environment, whether the response still supports headers, and whether
the request was filtered out such as ApacheBench. See [Quick Start](README.md#quick-start) and
[Runtime Switches](README.md#runtime-switches).

### The Inspector URL returns `404` {#link-404}

The payload may have expired, the backend may have changed, or the runtime file on this node may no longer exist. See
[Storage Settings](README.md#storage-settings).

### Inspector slows down requests {#slowdown}

Reduce active collectors, shorten retention, or keep Redis mode for normal debugging. See
[Storage Settings](README.md#storage-settings) and [Collector Composition](README.md#collector-composition).

### Sensitive data appears in payloads {#sensitive-data}

Filter that data in your collectors and keep Inspector exposure controlled.
