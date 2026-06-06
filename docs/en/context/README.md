# Context Package {#context-package}

`switon/context` keeps mutable service-owned state out of shared services. Use one context object when a service needs request- or coroutine-scoped data that should follow the current request or coroutine scope.

**Key features:**

- **Owner-bound state:** one service owns one dedicated `*Context` object.
- **Runtime-aware storage:** the same service code works in non-coroutine and coroutine runtimes.
- **Child scope control:** child coroutines inherit parent context by default; `ContextIsolated` opts into fresh state and child-scope events.
- **Core contract first:** application code injects `Switon\Core\ContextManagerInterface`; the package supplies the default binding.

---

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/context
```

---

## Quick Start {#quick-start}

1. Create one `*Context` class for the mutable fields that must follow the current request or coroutine scope.
2. Implement `Switon\Core\ContextAware` and inject `Switon\Core\ContextManagerInterface`.
3. Read and update state through `getContext()` instead of storing it on the service itself.

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;

final class RequestStateContext
{
    public string $userId = '';
}

final class RequestState implements ContextAware
{
    #[Autowired] protected ContextManagerInterface $contextManager;

    public function getContext(): RequestStateContext
    {
        return $this->contextManager->getContext($this);
    }

    public function setUserId(string $userId): void
    {
        $this->getContext()->userId = $userId;
    }

    public function userId(): string
    {
        return $this->getContext()->userId;
    }
}
```

---

## Core Concepts {#core-concepts}

### Owner-Bound State {#owner-bound-state}

The context class holds mutable fields. The service keeps behavior. If the value must reset with the current request or coroutine scope, do not store it directly on the service.

### Scope Resolution {#scope-resolution}

`Switon\Core\ContextManagerInterface` returns one context per owner and runtime scope.

- In non-coroutine runtimes, the same owner gets the same context object for the current manager instance.
- In coroutine runtimes, child coroutines reuse the parent context by default when one already exists.
- If coroutine mode is enabled but the current code is not inside a coroutine yet, the manager falls back to root storage.
- `ContextIsolated` opts into a fresh child context when child-local mutation is required.

Child-scope events:

- `ChildContextInherited`
- `ChildContextCreated`
- `ChildContextPropagated`

### Marker Contracts {#marker-contracts}

- `ContextAware` marks the service that owns the state.
- `ContextIsolated` marks context classes that must not be shared across child coroutines.
- `ContextConnScoped` marks connection-lifecycle state; this package only treats `ContextIsolated` specially.

### Context Class Rules {#context-class-rules}

- `getContext()` must declare one loadable context class.
- The context class must be instantiable with no required constructor arguments.
- Invalid declarations raise `Switon\Context\Exception\ContextException`.

---

## Configuration {#configuration}

There is no `switon.yml` block for this package.

- Inject `Switon\Core\ContextManagerInterface` in application code; the package provides the default implementation for you.
- `Switon\Context\ContextManagerInterface` is the helper contract for framework and test code, not the app-facing one.

---

## Keep in Mind {#keep-in-mind}

- Put mutable request state in the context object, not on the service.
- Use `#[Communal]` for intentionally shared properties; keep mutable request state in `Context`.
- Use `ContextIsolated` only when child coroutines must not share state with the parent.
- `resetContexts()` only clears the non-coroutine cache; root fallback storage stays alive.
- Use request attributes for one-step handoffs instead of creating a context class.

---

## Next {#next}

- **To compare Context with request attributes:** [HTTP Request](../http/request.md#request)
- **To understand propagation and isolation details:** [Design Rationale](design.md#design-rationale)
- **To answer common adoption questions:** [FAQ](faq.md#context-faq)
- **Implementation walkthrough (coroutine storage evolution):** [Context internals](internals/README.md)
