# Server and Deployment {#server-deployment}

Choose the HTTP runtime and server options for FPM, the PHP built-in server, or Swoole.

**Key features:**

- **Runtime selection** - `Switon\Http\ServerOptions::type` chooses `auto`, `php`, `fpm`, or `swoole`
- **Startup entry** - `Switon\Http\Kernel::start()` boots the app and starts the HTTP server
- **Adapter settings** - host, port, and adapter-specific settings live on `Switon\Http\ServerOptions`
- **Server events** - server lifecycle hooks cover startup, shutdown, and worker events

## Quick Start {#quick-start}

Set the server options in `switon.yml`.

```yaml
# switon.yml
Switon\Http\ServerOptions:
  type: auto
  host: 0.0.0.0
  port: 9501
```

`type: auto` is the default. Use `php`, `fpm`, or `swoole` only when you need to force one runtime.

## Core Concepts {#core-concepts}

### Runtime Selection {#runtime-selection}

`Switon\Http\ServerOptions` controls the server adapter and listen address.

- `host` sets the listen host
- `port` sets the listen port
- `type` selects the runtime adapter
- `settings` passes adapter-specific options through

### Startup {#startup-and-configuration}

`Switon\Http\Kernel::start()` boots the app first, then starts the server.

### Server Events {#server-events}

Use server events when you need startup, shutdown, or worker lifecycle hooks.

- `Switon\Http\Server\Event\ServerReady`
- `Switon\Http\Server\Event\ServerStart`
- `Switon\Http\Server\Event\ServerStarted`
- `Switon\Http\Server\Event\ServerWorkerStart`
- `Switon\Http\Server\Event\ServerShutdown`

### Nginx {#nginx}

Use Nginx as the reverse proxy when Swoole runs behind a web server boundary.

## Keep in Mind {#keep-in-mind}

- `type: auto` is the safest first choice.
- Put runtime-specific tuning in `Switon\Http\ServerOptions.settings`.
- Keep normal request handling in `RequestHandler`, not in server startup code.

## Next {#next}

- **To choose response behavior:** [Response](response.md#response)
- **To read request input:** [Request](request.md#request)
- **To inspect current options:** [FAQ](faq.md#http-faq)
