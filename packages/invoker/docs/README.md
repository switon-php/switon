# Invoker Package {#invoker-package}

`switon/invoker` runs guard-aware and intercepted method calls on top of the DI invoker. Choose it when one dispatch
step needs to share callable execution, argument resolution, and method-level hooks across HTTP, CLI, RPC, or custom
transports. The package does not build arguments from request input and does not own transport parsing.

**Key features:**

- **One callable contract:** array callables, closures, string callables, and invokable objects share one entry point.
- **Explicit overrides first:** positional, named, default, and type-keyed overrides win before DI fallback.
- **DI-backed execution:** `Switon\Di\InvokerInterface` still does the underlying callable execution and parameter
  resolution.
- **Method-level hooks:** `Switon\Invocation\Attribute\GuardInterface` runs before managed calls;
  `Switon\Invocation\Attribute\InterceptorInterface` wraps them.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/invoker
```

`Switon\Invoking\InvokerInterface` is the hook-aware entry point. The hook contracts themselves live
in [Invocation Package](../../invocation/docs/README.md).

## Quick Start {#quick-start}

Inject `Switon\Invoking\InvokerInterface` where the call site already has the target callable and any explicit
overrides, then call `invoke()`.

```php
namespace App\Dispatch;

use Switon\Core\Attribute\Autowired;
use Switon\Core\AppInterface;
use Switon\Invoking\InvokerInterface;

class Dispatcher
{
    #[Autowired] protected InvokerInterface $invoker;

    public function dispatch(object $controller, string $action, int $id): mixed
    {
        return $this->invoker->invoke([$controller, $action], ['id' => $id]);
    }
}

class PostController
{
    public function showAction(int $id, AppInterface $app): array
    {
        return ['id' => $id, 'app' => $app->id()];
    }
}
```

The dispatcher supplies the callable boundary and the explicit `id`. `AppInterface` falls back to DI.

If the argument list still needs to come from request input, typed input, or builders, resolve that first
in [Binding Package](../../binding/docs/README.md).

## Core Concepts {#core-concepts}

### Callable Boundary {#callable-boundary}

Invoker accepts the normal PHP callable forms that make sense at dispatch boundaries: array callables such as
`[$controller, 'showAction']`, closures, string callables, and invokable objects. Use `invoke()` when the caller already
knows the final target and wants one consistent execution path.

### Argument Resolution {#argument-resolution}

`Switon\Di\InvokerInterface` resolves parameters in this order:

1. explicit positional override
2. explicit named override
3. default value
4. type-keyed object override
5. DI fallback (autowiring or container lookup)

Variadic parameters take a named or type-keyed array first, otherwise pick up trailing positional values.

This step only resolves the final callable arguments; input construction belongs
to [Binding Package](../../binding/docs/README.md).

### Invocation Hooks {#invocation-hooks}

Hooks attach to managed method calls only.

- guards run before the method body and receive the target `ReflectionMethod` through their `handle()` method
- interceptors wrap the managed call: `preHandle()`, then the method body, then `postHandle()`; `onException()` runs on
  throw
- `preHandle()` returning `false` does not skip the call; throw to block it
- `postHandle()` receives the return value by reference and may replace it
- direct method calls such as `$service->run()` bypass both stages
- constructors and plain function callables run through the DI invoker without hooks

## Configuration {#configuration}

Invoker is configuration-light. `Switon\Invoking\InvokerInterface` resolves through the standard DI setup, and the
package adds no option surface of its own.

`Switon\Di\InvokerInterface` remains the lower-level argument resolver and callable executor for framework code that
does not want hooks.

## Keep in Mind {#keep-in-mind}

- DI still owns autowiring, shared services, named slots, and plain callable execution; invoker only adds the hook layer
  on top.
- Direct method calls do not trigger guards or interceptors.
- If you only have a `ReflectionMethod`, build the callable boundary first and then call `invoke()`.

## Next {#next}

- **To build arguments before invocation:** [Binding Package](../../binding/docs/README.md)
- **To define guard and interceptor contracts:** [Invocation Package](../../invocation/docs/README.md)
- **To answer common questions:** [FAQ](faq.md#invoker-faq)
- **To understand the split with binding:** [Design Rationale](design.md#design-rationale)
