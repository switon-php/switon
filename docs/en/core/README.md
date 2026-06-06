# Core Package {#core-package}
`switon/core` ships the shared contracts, attributes, and helpers that the rest of Switon builds on. It standardizes the framework's vocabulary — autowiring, app metadata, runtime scene, context state, paths, helpers — without owning feature domains or startup order. Most apps consume it transitively through other Switon packages.

**Key features:**

- **Property autowiring:** `#[Switon\Core\Attribute\Autowired]` plus `Switon\Core\Lazy` cover the normal injection path.
- **App metadata:** `Switon\Core\AppInterface` exposes id, name, version, environment, debug flag, and timezone.
- **Runtime scene:** `Switon\Core\SceneManagerInterface` keeps the current scene (`http`, `cli`, `schedule`, ...) separate from app metadata.
- **Context state:** `Switon\Core\ContextAware` and `Switon\Core\ContextManagerInterface` keep mutable request or coroutine state out of shared services; `switon/context` ships the default manager.
- **Shared helpers:** `Json`, `Strings`, `Naming`, `ClassName`, `Backtrace`, `Filesystem`, `Random`, `Clock`, `FilterMatcher`, plus the global functions `make()`, `env()`, `json()`, `console_log()`, `array_first()`, `array_last()`.
- **Paths:** `Switon\Core\Runtime` and `Switon\Core\PathAliasInterface` resolve `@root`, `@app`, `@runtime`, `@vendor`, `@resources`, and custom aliases.
- **Contract split:** Core publishes interfaces and markers; sibling packages (DI, kernel, context, ...) provide the runtime defaults.

## Installation {#installation}

**Requirements:** PHP 8.3+, `ext-json`

```bash
composer require switon/core
```

## Quick Start {#quick-start}

Inject `AppInterface` and use shared helpers wherever framework code needs them.

```php
namespace App\Service;

use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Json;

class StatusReporter
{
    #[Autowired] protected AppInterface $app;

    public function snapshot(): string
    {
        return Json::stringify([
            'app' => $this->app->name(),
            'env' => $this->app->env(),
            'debug' => $this->app->isDebug(),
        ], JSON_PRETTY_PRINT);
    }
}
```

## Core Concepts {#core-concepts}

### Autowiring {#autowiring}

`#[Autowired]` is the property-injection attribute. The DI package reads it; Core only declares the marker.

- Inject services on protected or public properties.
- Pair `Lazy` with a union type (`FooInterface|Lazy`) when a dependency should remain in the type graph but resolve on first use; DI wraps it in `LazyPropertyProxy`.
- For arrays, `instances: true` resolves each entry as a service instance instead of a plain config value.
- Building an autowired class with `new` skips injection. Let the container or `MakerInterface::make()` create it when you expect injection to run.

### App metadata vs scene {#app-metadata-vs-scene}

`AppInterface` and `SceneManagerInterface` are intentionally split.

- `AppInterface` answers "what app is this and how is it deployed" (id, name, version, env, debug, timezone). Treat it as read-only metadata.
- `SceneManagerInterface` answers "what runtime is currently executing" (`http`, `cli`, `schedule`, ...). Use `#[Scene('cli')]` to mark a bootstrap entry class with its scene.
- Mutable per-request or per-connection state belongs in `ContextAware`, not on `AppInterface` and not on shared services.

### Context state {#context-state}

`ContextAware` and `ContextIsolated` mark properties that must live in a per-request or per-coroutine context object. `ContextManagerInterface` is the contract; `switon/context` ships the default implementation. `ContextConnScoped` is a descriptive marker for connection-lifetime data; the default manager does not branch on it.

`#[Communal]` is a convention marker for properties that are intentionally shared across contexts; Core does not enforce shared-property behavior at runtime.

### Paths and runtime {#paths-and-runtime}

- `Runtime::getRoot()` resolves the project root. It reads `SWITON_ROOT` first, then falls back to Composer's `autoload.php` location. `Runtime::setRoot()` overrides it for tests or bootstrap.
- `PathAliasInterface` expands `@root`, `@app`, `@runtime`, `@vendor`, `@resources`, plus aliases registered through `#[ResourceAlias]`.

### Helpers and exceptions {#helpers-and-exceptions}

Core ships small utility classes and a few global helpers; both stay framework-consistent so packages can reuse them without rebuilding the same primitives.

- Classes: `Json`, `Strings`, `Naming`, `ClassName`, `Backtrace`, `Filesystem`, `Random`, `Clock`, `FilterMatcher`.
- Globals (autoloaded with Core): `make()`, `env()`, `json()`, `console_log()`, `array_first()`, `array_last()`.
- Exceptions: `Switon\Core\Exception::raise($template, $context)` builds the message from `{key}` placeholders and stores `$context` on the exception for downstream handlers.

### Static bootstrap bridge {#static-bootstrap-bridge}

`Switon\Core\App` is the static container bridge for bootstrap and compatibility edges only. `App::get()` returns shared services and `App::make()` returns fresh instances. Once the framework is running, prefer injected `AppInterface`, `Switon\Core\ContainerInterface`, or `Switon\Core\MakerInterface`.

## Keep in Mind {#keep-in-mind}

- Core publishes contracts; bootstrap order, container wiring, and provider discovery live in `switon/kernel` and `switon/di`.
- Treat `AppInterface` as metadata only; scene and request state belong in their own contracts.
- `App::get()` and `App::make()` are bootstrap-only; using them inside services hides dependencies that DI would surface.
- `#[Communal]` is a marker only; the runtime does not enforce sharing on its own.

## Next {#next}

- **For common questions:** [FAQ](faq.md#core-faq)
- **For trade-offs and rationale:** [Design Rationale](design.md#design-rationale)
- **For startup order and provider discovery:** [Kernel Package](../kernel/README.md)
- **For the default container behavior:** [DI Package](../di/README.md)
