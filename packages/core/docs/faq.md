# Core FAQ {#core-faq}
Short answers for common Core questions.

> **Prerequisites:** [Core Package](README.md)

## Getting Started {#getting-started}

### Do I need `switon/core` directly? {#direct-install}

Only when you use Core contracts or helpers explicitly. Most Switon stacks pull it in transitively.

### How do I find the project root? {#project-root}

Call `Switon\Core\Runtime::getRoot()`. It derives the root from Composer's `autoload.php` location. `Runtime::setRoot()` overrides it for tests or non-standard bootstrap.

## Contracts {#contracts}

### When do I use `AppInterface`? {#when-app-interface}

For app metadata only: id, name, version, environment, debug flag, and timezone. Runtime scene and request state belong elsewhere. See [App metadata vs scene](README.md#app-metadata-vs-scene).

### Why is `SceneManagerInterface` separate? {#scene-split}

So app metadata stays read-only and scene stays mutable per runtime. Use `#[Switon\Core\Attribute\Scene('cli')]` on a kernel entry class and read the current scene through `SceneManagerInterface`.

### When should I use `ContextAware`? {#when-context-aware}

When mutable per-request or per-connection state needs to live in a context object instead of a shared service. The default manager ships in `switon/context`.

### When should I use `PathAliasInterface`? {#when-path-alias}

When code should accept aliases such as `@root`, `@app`, `@runtime`, `@vendor`, `@resources`, or custom aliases registered with `#[Switon\Core\Attribute\ResourceAlias]` instead of raw filesystem paths.

### What does `#[Communal]` do? {#what-communal}

It is a convention marker for properties that are intentionally shared across contexts. Core does not enforce shared-property behavior at runtime; consumers decide what to do with the marker.

### What does `Lazy` mean? {#lazy}

It marks a dependency for delayed resolution. Use it in a union type, e.g. `protected FooInterface|Lazy $foo`, and DI wraps the property in `Switon\Di\LazyPropertyProxy` until the first call.

## Bootstrap {#bootstrap}

### Can I call `App::get()` in normal code? {#app-get}

Prefer injected `AppInterface`, `Switon\Core\ContainerInterface`, or `Switon\Core\MakerInterface`. Use `App::get()` and `App::make()` only at bootstrap edges or compatibility shims.

### Why did `#[Autowired]` stay `null`? {#autowired-null}

The object was probably built with `new` instead of through the container or invoker. Let the framework create it (via DI or `MakerInterface::make()`) when you expect injection to run.

### Should I use `raise()` instead of `new`? {#raise}

Use `Switon\Core\Exception::raise($template, $context)` for framework exceptions to keep `{key}` placeholders and structured context. Use `new` only for plain PHP exceptions that do not need that shape.
