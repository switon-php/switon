# Understand config and conventions {#config-and-conventions}

How to read common Switon config and example syntax such as `switon.yml`, `${APP_ENV:-prod}`, `#cache`, `@view`, or same-namespace `XxxInterface` -> `Xxx`.

## The shortest reading model {#reading-model}

When you see Switon docs, start with these five rules:

1. root `switon.yml` is the normal application config file
2. each top-level YAML key is usually one service id or contract
3. `${VAR}` and `${VAR:-default}` read environment values
4. `#name` usually means "named service instance under the current contract"
5. `@app/...` and `@view/...` are path aliases, not literal folders starting with `@`

If you remember only that much, most beginner examples become readable enough to keep going.

## How to read `switon.yml` {#yml}

For most applications, `switon.yml` lives at the project root.

Read a block like this:

```yaml
Switon\Http\ExceptionDispatcherInterface:
  handlers:
    App\Exception\MaintenanceException: App\ExceptionHandler\MaintenanceHandler
```

as:

- `Switon\Http\ExceptionDispatcherInterface` = the thing you are configuring
- `handlers` = one option on that thing
- each nested entry = one value for that option

Another example:

```yaml
Psr\SimpleCache\CacheInterface:
  prefix: 'tasks:list:'
  redisCache: '#cache'
```

Read it as:

- configure `Psr\SimpleCache\CacheInterface`
- set its `prefix` option to `tasks:list:`
- tell its `redisCache` slot to use the named Redis instance `#cache`

The shortcut is simple: top-level key = what is being configured; nested keys = how it should behave.

## Common config syntax {#common-config-syntax}

### Environment placeholders {#environment-placeholders}

```yaml
env: ${APP_ENV:-prod}
uri: ${REDIS_URI:-redis://127.0.0.1:6379/0}
```

Read these as:

- `${APP_ENV}` = use the `APP_ENV` environment variable
- `${APP_ENV:-prod}` = use `APP_ENV`, or fall back to `prod` when it is missing or empty

This is configuration placeholder syntax prepared during bootstrap. It is not PHP syntax and not shell code you need to execute manually inside YAML.

### Named-service references {#named-service-references}

```yaml
redisCache: '#cache'
```

`#cache` means "the named `cache` instance under the current contract".

In a config like:

```yaml
Switon\Redis\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: redis://localhost:6379/0
  cache:
    uri: redis://localhost:6379/1
```

the framework expands that into:

- default Redis client
- named Redis client `Switon\Redis\ClientInterface#cache`

So `'#cache'` is just the short reference form for that named instance.

### Path aliases {#path-aliases}

```yaml
layout: '@view/Layouts/Default'
```

Read aliases literally:

- `@app` = application root
- `@view` = view root, which usually defaults to `@app/View`
- `@root` = project root
- `@runtime` = runtime directory

So `@view/Layouts/Default` usually points to something under `app/View/Layouts/Default` with the real template extension resolved later.

## Common code conventions {#common-code-conventions}

### Same-namespace DI convention {#namespace-di-convention}

When docs say same-namespace DI convention, they mean this pattern:

```php
namespace App\Service;

interface TaskServiceInterface
{
}

class TaskService implements TaskServiceInterface
{
}
```

If the interface and implementation follow the normal `XxxInterface` -> `Xxx` naming in the same namespace, Switon can autowire that pair without an extra explicit binding in `switon.yml`.

Use explicit config when:

- the implementation has a different name
- the implementation lives in another namespace
- one interface has multiple implementations

### Named slots in properties {#named-slots-properties}

When you see code like this:

```php
<?php

namespace App\Example;

use Psr\SimpleCache\CacheInterface;
use Switon\Core\Attribute\Autowired;

class ExampleServices
{
    #[Autowired] protected CacheInterface $cache;
    #[Autowired] protected CacheInterface $session;
}
```

the property names are not just variable names. In a `Factory` setup, they act as slot names:

- `$cache` -> `ClientInterface#cache`
- `$session` -> `ClientInterface#session`

That is why docs sometimes say "property name selects the named instance".

### `toArray()` in examples {#toarray-in-examples}

When a guide shows `$entity->toArray()`, do not assume every PHP object in Switon has that method.

Usually one of these is true:

- the object is an ORM entity, which provides `toArray()`
- the example is returning a normalized array shape on purpose before caching, rendering, or JSON output

If a class example never defined `toArray()`, the doc should say where that method comes from. If it does not, treat that as a doc gap, not a framework-wide rule.

## When a guide says "put this in config" {#config}

Use this quick rule:

- request-wide HTTP behavior -> usually `Switon\Http\RequestHandlerInterface`
- HTTP exception mapping -> usually `Switon\Http\ExceptionDispatcherInterface`
- cache runtime options -> usually `Psr\SimpleCache\CacheInterface`
- mail transport options -> usually `Switon\Mail\MailerInterface`
- app metadata and bootstrap-level values -> usually root `switon.yml` entries owned by `Kernel` or `Core`

If you know the contract name, `bash bin/console config:options '<FQCN>'` is the fastest way to confirm the current option surface in your installed version.
