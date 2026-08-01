# Invocation Package {#invocation-package}

`switon/invocation` defines **method-level hook contracts** for guards and interceptors. It ships the attributes and interfaces only; [Invoker](https://github.com/switon-php/invoker/blob/main/docs/README.md) runs those hooks when you call through `Switon\Invoking\InvokerInterface`.

**Key features:**

- **Attribute-based hooks:** attach guards and interceptors to managed methods with PHP attributes.
- **Pre-execution guards:** classes implementing `Switon\Invocation\Attribute\GuardInterface` run before interceptors and the target method; throwing blocks the call.
- **Around-call interceptors:** `Switon\Invocation\Attribute\InterceptorInterface` defines `preHandle`, `postHandle`, and `onException` hooks around each managed call.
- **No-op base class:** extend `Switon\Invocation\Attribute\Interceptor` when you only need one or two lifecycle hooks.

---

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/invocation
```

---

## Quick Start {#quick-start}

The guard's `handle()` receives the current `ReflectionMethod` plus any typed dependency the invoker can resolve. Throw to block execution.

```php
use Attribute;
use ReflectionMethod;
use Switon\Invocation\Attribute\GuardInterface;
use Switon\Http\Exception\ForbiddenException;
use Switon\Http\RequestInterface;

#[Attribute(Attribute::TARGET_METHOD)]
final class IpGuard implements GuardInterface
{
    public function __construct(
        private array $ips,
    ) {
    }

    public function handle(RequestInterface $request, ReflectionMethod $method): void
    {
        if (!in_array($request->ip(), $this->ips, true)) {
            throw ForbiddenException::of('Access denied.');
        }
    }
}

final class AccountService
{
    #[IpGuard(['127.0.0.1'])]
    public function deleteAction(int $id): void
    {
    }
}
```

---

## Core Concepts {#core-concepts}

### Guards {#guards}

`Switon\Invocation\Attribute\GuardInterface` marks a method-level guard attribute.

- implement a public `handle(...)` method on the attribute class
- guards run **before** interceptors and the target method when the invocation pipeline is enabled
- throw from `handle()` to block the call

### Interceptors {#interceptors}

`Switon\Invocation\Attribute\InterceptorInterface` defines the around-call lifecycle:

- `preHandle(ReflectionMethod $method): bool` runs before the method body; throw to block.
- `postHandle(ReflectionMethod $method, mixed &$return): void` runs after success and may replace the return value through the reference.
- `onException(ReflectionMethod $method, \Throwable $exception): void` runs when the target throws.

`preHandle()` returning `false` does **not** skip the call; throw to block instead. Extend `Switon\Invocation\Attribute\Interceptor` when you only need one or two of these hooks.

### Runtime Boundary {#runtime-boundary}

This package defines contracts only.

- Hook execution happens through [Invoker](https://github.com/switon-php/invoker/blob/main/docs/README.md); direct calls such as `$service->deleteAction(1)` bypass guards and interceptors.
- Argument building from HTTP, CLI, or builders belongs to [Binding](https://github.com/switon-php/binding/blob/main/docs/README.md), not this package.

---

## Keep in Mind {#keep-in-mind}

- Guards and interceptors attach to **methods** with PHP attributes, not to whole classes.
- Hook behavior applies only on invoker-managed calls.
- Attribute constructor arguments come from the attribute usage site; the guard `handle()` method itself can declare typed dependencies (for example `Switon\Http\RequestInterface`) that the invoker resolves before calling it.

---

## Next {#next}

- **To run guarded and intercepted methods through one entry point:** [Invoker Package](https://github.com/switon-php/invoker/blob/main/docs/README.md)
- **To resolve arguments from HTTP, CLI, or builders before `invoke()`:** [Binding Package](https://github.com/switon-php/binding/blob/main/docs/README.md)