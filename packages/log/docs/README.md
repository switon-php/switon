# Log Package {#log-package}
`switon/log` gives Switon apps a single PSR-3 logger with automatic categories, structured context, and category-aware level filtering. Choose it when you want **one** stable logging contract across HTTP, CLI, workers, and tests. The default model does not include handler trees or per-destination routing rules.

**Key features:**

- **Standard logger contract:** inject `Psr\Log\LoggerInterface` and call normal PSR-3 methods.
- **Automatic categories:** derived from the **call site** by default; `Switon\Core\Categorizable` can override.
- **Structured context:** `{key}` placeholders render into the message; everything else stays in `extra`.
- **Category-aware filtering:** combine one global level with `levels` overrides; longest-prefix rules scope changes to a category prefix.
- **Output appenders:** built-in appenders cover file, stdout, syslog, and memory output.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/log
```

`Psr\Log\LoggerInterface` is bound to `Switon\Logging\Logger` by default. The default binding reads `LOG_LEVEL`, falls back to `debug`, and writes accepted entries through a file appender.

## Quick Start {#quick-start}

Inject `Psr\Log\LoggerInterface` and call normal PSR-3 methods.

```php
namespace App\Service;

use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;

class UserService
{
    #[Autowired] protected LoggerInterface $logger;

    public function register(int $userId): void
    {
        $this->logger->info('User {user_id} registered', [
            'user_id' => $userId,
        ]);
    }
}
```

By default, accepted entries write to `@runtime/logger/{scene}.log`. `{scene}` is the current **runtime scene** from the framework scene manager (for example `http`, `cli`, or `schedule`) — one log file per scene, not per request.

## Core Concepts {#core-concepts}

Order follows the pipeline: **category and level**, then **message shape**, then **output**.

### Category {#category}

A **category** is a dot-separated identifier used for `levels` filtering. It is **not** a file path and **not** a per-line channel label.

By default, the logger derives the category from the **call site**: the first stack frame that has a class (closures skipped), plus the method name, mapped to a stable dot-separated id. For example, `App\Service\UserService::register()` becomes `app.service.user.register`. If the method name ends in `Action`, that suffix is removed first (`registerAction` → `register`).

Overrides:

- Pass a `message` argument that implements `Switon\Core\Categorizable` to set the category explicitly.
- when `context['exception']` is set, the category is taken from the **throw** location, not from the `catch` block that logs

### Log Flow {#routing}

Logging stays on one simple path:

`log()` -> category -> level filter -> `LogEntry` -> appenders

- `LOG_LEVEL` supplies the default level when no `levels` prefix matches the category.
- With `levels`, the longest matching category **prefix** sets the **threshold** for that call. Calls at levels **more verbose** than that threshold are dropped (for example `debug` when the effective threshold is `info`).
- `LoggerLogged` listeners can add fields to `LogEntry::extra` before appenders run; see [FAQ](faq.md#logger-logged-boundaries)

### Message and context {#message}

**Pattern:** Put the human-readable sentence in the message. Put per-call fields in `context`.

- keep the message readable on its own
- use `{key}` only for values that belong in the sentence
- keys that match `{key}` placeholders are interpolated into the message
- keys that do not match a placeholder stay in `extra` as structured data
- `context['exception']` renders a throwable and can carry Switon exception context

This keeps the sentence readable for humans and the remaining data available for structured output.

```php
try {
    $this->createOrder($items);
} catch (\Throwable $e) {
    $this->logger->error('Order creation failed', ['exception' => $e]);
}
```


The logger stores a `LogEntry`: interpolated keys stay in `message`, leftover context stays in `extra`, and `exception`
is rendered into `message` instead of `extra`. For `Switon\Core\Exception`, `getContext()` merges into `extra` (caller
keys win on conflict).

### Output {#output}

Accepted entries go to every configured appender, in order.

- built-ins cover file, stdout, syslog, and memory output
- appenders are independent destinations, but the logger calls them sequentially; a throwing appender stops later ones
- the built-in file appender reports write failures on a separate channel instead of failing the request (see [FAQ](faq.md#file-write-failure))
- `FormatterInterface` controls text or JSON output

## Configuration {#configuration}

Start with one destination and one global level. Add per-category overrides only when one part of the app logs too much or too little.

Configure this package in `switon.yml`:

```yaml
# switon.yml
Psr\Log\LoggerInterface:
  level: info
  levels:
    app.service.payment: warning
```

### Option Discovery {#option-discovery}

Run:

```bash
bash bin/console config:options 'Psr\Log\LoggerInterface'
```

## Keep in Mind {#keep-in-mind}

- If logs do not appear, check the destination and effective level first.
- If only some messages appear, inspect the longest matching prefix in `levels`, including defaults on the logger binding
  (run `config:options` or check your merged config, not only `switon.yml`).
- Add request metadata or correlation ids in a `LoggerLogged` listener instead of repeating them at every call site.

## Next {#next}

- **To debug behavior and defaults:** [FAQ](faq.md#log-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
