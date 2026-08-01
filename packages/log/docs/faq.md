# Log FAQ {#log-faq}
Short answers for setup, configuration, and common logging issues.

> **Prerequisites:** [Log Package](README.md)

## Getting Started {#getting-started}

### How do I add logging to my code? {#add-logging}

Inject `Psr\Log\LoggerInterface` and call normal PSR-3 methods. See [Quick Start](README.md#quick-start).

### Where do logs go by default? {#default-destination}

The default provider wiring writes accepted entries to `@runtime/logger/{scene}.log`. In containerized deployments, use
`Switon\Logging\Appender\StdoutAppender` instead of file output so entries reach the platform log collector.

### Can I use the same logger across the whole app? {#same-logger}

Yes. The package is designed for one PSR-3 contract across HTTP, CLI, workers, and libraries.

## Configuration {#configuration}

### How do I change the log level? {#change-level}

Set `level` for the global baseline, then add `levels` entries for category-specific overrides. See
[Configuration](README.md#configuration).

### How do category overrides work? {#category-overrides}

`levels` uses prefix matching on the category. The longest matching prefix sets the **threshold** for that call. Calls at levels **more verbose** than that threshold are dropped. See [Configuration](README.md#configuration).

### Can I route one category to one appender? {#route-by-category}

No. Accepted entries fan out to every configured appender. Use `levels` to reduce noise, not to split destinations.

### If one appender fails, do the others still run? {#appender-failure}

The logger calls appenders in order without catching errors. The file appender isolates write failures; a custom appender that throws stops later appenders and may bubble to your code. See [Output](README.md#output).

### What can I change in a LoggerLogged listener? {#logger-logged-boundaries}

Add fields to `LogEntry::extra` before appenders run. Treat `level`, `category`, and `message` as fixed for that entry. Do not call the logger again from the listener.

### How do I inspect the available options in my version? {#discover-options}

Run `bash bin/console config:options 'Psr\Log\LoggerInterface'`. If you need appender-specific options, query the
appender class directly.

## Behavior {#behavior}

### What category will my code get? {#predict-category}

The category comes from the class and method at the **call site** (for example `App\Service\UserService::register()` →
`app.service.user.register`). `Switon\Core\Categorizable` overrides that default, and when `context['exception']` is
present the logger uses the throw location instead of the catch site. See [Category](README.md#category).

### How do I format messages and log exceptions? {#format-messages}

Write the sentence in the message, use `{key}` placeholders for sentence values, and pass throwables under
`context['exception']`. The logger builds a `LogEntry`: interpolated keys stay in `message`; other keys go to `extra`;
`exception` is rendered into `message` instead of `extra`. See [Message and context](README.md#message).

### How do I add request ids or other shared fields? {#add-correlation}

Listen for `Switon\Logging\Event\LoggerLogged` and add fields to `LogEntry::extra` before appenders run. Treat
`level`, `category`, and `message` as fixed for that line, and do not call `Psr\Log\LoggerInterface` from the listener.
Keep that layer lightweight and limited to cross-cutting enrichment.

### Can I use `MemoryAppender` in tests? {#memory-appender}

Yes. `Switon\Logging\Appender\MemoryAppender` is the normal test-friendly in-memory destination. Entries are isolated per
request or coroutine context.

## Troubleshooting {#troubleshooting}

### Why are logs missing? {#logs-missing}

Check the destination and effective level first. In practice, most misses come from the wrong appender target or a
prefix-specific `levels` override.

### Why do some logs appear but others do not? {#partial-logs}

A more specific category override is probably filtering them out. Check the longest matching prefix in `levels`, including
defaults shipped with the logger binding (not only entries you added in `switon.yml`).

### What happens when the file appender cannot write? {#file-write-failure}

The file appender reports the failure on a separate channel. Do not log again through `Psr\Log\LoggerInterface` from that failure path.

### How do I test code that logs? {#testing}

Use `Switon\Logging\Appender\MemoryAppender` or a stub `Psr\Log\LoggerInterface`.
