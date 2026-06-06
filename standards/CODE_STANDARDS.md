# Switon Code Standards

Applies to packages under `packages/*`.

## Scope

- Packages follow all sections.
- App code may relax `Visibility`, `Console output`, `Directory Organization`, `Configuration Files`, `Exception Design`, and `Logging`.
- `Dependency Injection`, `Interface-Based Design`, `PHPDoc boundary`, and `Application Configuration` are still good defaults for app code.

---

## Dependency Injection (MANDATORY)

- ✅ Always use `#[Autowired]` for service and config dependencies.
- ✅ Scalar and array config may also use `#[Autowired]`.
- ✅ Always inject interfaces, not concrete classes.
- ❌ Never use `new` for dependencies, except temporary bootstrap objects when the container is unavailable.
- ❌ Never use global helpers such as `config()`.
- ⚠️ `ContainerInterface` is allowed at framework boundary code that performs dynamic resolution, factories, named services, or invocation. Ordinary services should inject their real dependencies directly.

### Interface-first injection

- Inject interfaces by default.
- Treat concrete-class autowiring as an error when a matching `*Interface` exists.
- Use explicit IDs only for named services, aliases, factory-backed selections, or intentionally lazy resolution.

### Property naming for injected dependencies

- Use property names as semantic slot names for injected dependencies.
- Service property names should describe the dependency role or type directly, such as `$userRepository`, `$cacheFactory`, or `$mailer`.
- Do not hide a service dependency behind a vague alias or collection-shaped name such as `$users` when the slot is actually a `UserRepositoryInterface`.
- For config-injected scalar or array properties, prefer single-word names when they stay clear.
- Let config, factory definitions, or named-service wiring choose the concrete target; do not encode that selection into an unclear property alias.

### Named services and defaults

- Use `Type#name` as the standard model for named service instances.
- Keep the plain `Type` as the default entry.
- Factory-backed multi-instance services must define the default strategy intentionally.
- If the default instance is missing, fail fast instead of guessing another named instance.

### Array injection semantics

- Default array injection means config array, not service array.
- Use `#[Autowired(instances: true)]` only when each array item should resolve as a service instance or service definition.
- Each `instances: true` array item must be either a service ID string or an inline definition with `class`.
- For plain config arrays, property defaults may act as merge defaults.
- For merged config arrays, `null` removes the inherited default key.
- `Traversable` config replaces the array as a whole instead of merging into defaults.

### Object autowiring rules

- Property-name binding is the slot selector for named services.
- Relative references such as `#redisDb` are config/runtime syntax and resolve against the property type.
- Nullable object types do not make `#[Autowired]` optional. Unresolved object dependencies must still fail fast.
- Union autowiring is allowed only when the object target stays uniquely determinable.
- `Lazy|Type` is the preferred delayed-resolution pattern.
- Do not use broad union types as a generic escape hatch for autowiring.

### Injection timing

- `#[Autowired]` properties are injected before the constructor runs.
- Order: `newInstanceWithoutConstructor()` → set autowired properties → call constructor.
- Constructors may use autowired properties.
- Do not add defensive workarounds for a later-injection model.
- Do not add PHPDoc just to restate this timing.

### PHPDoc for autowired properties

- Typed `#[Autowired]` properties do not need `@var`.
- Add `@var` only when PHPDoc adds precision beyond the native type, for example `@var array<string, string>`.
- `#[Autowired]` must stay on the same line as the property declaration, with one space after the attribute: `#[Autowired] protected string $name`.
- Do not repeat detailed property PHPDoc rules in this file.

### Renaming autowired properties

- If an `#[Autowired]` property is renamed, do not add code-level compatibility aliases for the old property name.
- Treat the new property name as the only supported contract in code.
- When the old name was user-visible in config or docs, update docs and examples to the new name instead of merging old and new names in the implementation.

### Container-facing authoring guidance

- Prefer stable authoring contracts over container implementation details.
- When DI behavior is hard to find, explain the path with PHPDoc `Road-signs` on the nearest entrypoint.
- Keep alias depth, temporary cycle-breaking caches, and convenience sugar out of standards unless they become intentional public contracts.

---

## Interface-Based Design (MANDATORY)

- ✅ Depend on interfaces.
- ✅ Define interfaces for public APIs.
- ✅ Use the `ComponentNameInterface` naming pattern.
- Packages should give each public service class a corresponding interface.
- App code may use concrete classes without defining an interface.

### Service binding decision

- Default: no `ServiceProvider` binding when the interface and implementation share the expected namespace and naming pattern.
- Add explicit `ServiceProvider::register()` binding only when:
  - interface and implementation are in different namespaces; or
  - alias, factory, named-service, or non-default implementation behavior is needed.
- Do not add redundant auto-map bindings such as `FooInterface` → `Foo` when the default namespace and naming convention already resolve them.
- Example: `Switon\I18n\ServiceProvider` binds `LocaleInterface` → `Locale` because the interface lives in Core while the implementation lives in I18n.

---

## Service Providers (MANDATORY when present)

- Use `ServiceProvider` as the package startup entrypoint.
- `register()` is for bindings, aliases, and container definitions only.
- `boot()` is for runtime initialization, listener registration, scanning, and warm-up only.
- Do not move container definition writes into `boot()`.
- Package-owned resource aliases that follow standard package layout should use `#[ResourceAlias]` on the provider.
- Standard layout means `packages/{name}/src` in monorepo and `vendor/{vendor}/{name}/src` after installation.
- Alias generation treats monorepo `packages` as alias vendor `switon`, so `packages/http/src` maps to `@switon.http.resources`.
- Non-standard layouts should register aliases manually instead of bending `#[ResourceAlias]`.

---

## FPM/Swoole Compatibility (MANDATORY for stateful components)

- ✅ Stateful components must implement `ContextAware`.
- ✅ Store mutable state in `ComponentNameContext`.
- ❌ Never store cross-request state in service properties.

---

## Visibility (MANDATORY)

- ✅ Use `protected` by default for methods and properties.
- ⚠️ Use `private` only when subclass access must be blocked.
- ✅ DTO, event, and context objects may use public properties when they act as plain data carriers.
- ❌ Do not expose public mutable state on service objects.

---

## Code Style (MANDATORY)

- ✅ Use `declare(strict_types=1);`.
- ✅ Add type hints for all parameters and return values.
- ✅ Target PHP 8.3+.
- ✅ Use 4 spaces.
- ✅ Keep lines within 120 characters.
- ⚠️ Avoid emojis in code and comments unless they add real clarity.

### PHPDoc boundary

- Keep this file focused on package code rules, not detailed PHPDoc wording.
- Do not restate source-level PHPDoc rules here.

### Console output (MANDATORY)

- ✅ Always use `console_log()` for console output.
- ❌ Never use `echo`, `print`, or `fwrite(STDERR)`.
- Levels: `error`, `warning`, `info`, `debug`.

### Discovery-style APIs (SHOULD)

- Match verbs to side effects and return shape.
- Use `discover(): void` to populate registries.
- Use `discover(): array` to return cached candidates or maps without registry mutation.
- Use `resolve(): array` for container-backed resolution.
- Use `register(...)` to add discoverable units to registries.
- Use `on()` only for direct event-class plus callable binding on the same provider.

### Static method boundary

- Use static methods only for pure utilities, factory entrypoints, or boundary bridges.
- Default service and business behavior to instance methods.
- Keep `App::get()` and `App::make()` as boundary bridges, not as the default dependency access pattern.

---

## Directory Organization

- Put the interface in the parent directory.
- Put implementations in a subdirectory.
- Prefer singular subdirectory names such as `Exception/`, `Event/`, and `Relation/`.
- Plural names are fine when they read naturally, for example `Collectors/`.
- Keep related implementation-side classes, such as contexts, near the implementations they support.

## Configuration Files (MANDATORY)

- Put package config files under `config/`.
- Name config files from the interface name without `Interface`, in lowercase.
- Example: `DbInterface` → `config/db.php`.
- ✅ `env()` is allowed in config files.
- ⚠️ Avoid `env()` in components; prefer injected config values.
- Exception: system env such as `HOME` or `USERPROFILE` for system-level operations.

---

## Application Configuration (`App`)

- Use `App` only when the component needs global app settings.
- Access it by injecting `AppInterface`; use `App::get(AppInterface::class)` only at bootstrap or framework edges.
- Common properties: `id`, `name`, `env`, `debug`, `version`, `timezone`.

---

## Exception Design (MANDATORY)

- ✅ Always use Switon exceptions under `Switon\Core\Exception\*`.
- ✅ Use `Exception::raise()` with message templates.
- ❌ Never use PHP built-ins such as `\RuntimeException` or `\InvalidArgumentException` for package errors.
- ❌ Never `throw new Exception()` or `throw new ExceptionClass()` directly.
- ✅ Create specific exception classes per scenario.
- ❌ Do not add `for*` factory methods in exception classes.

### Exception PHPDoc and messages

- Keep exception and event PHPDoc to one sentence by default.
- Expand only for non-obvious behavior such as status mapping, protocol contract, or special factory/context rules.
- Keep messages self-explanatory and single-line.
- Include context.
- Use `{placeholder}` for values.
- Add actionable hints when helpful.
- Avoid vague messages such as `Not found`, `Invalid`, or `Missing`.

---

## Logging (MANDATORY)

- Prefer events over direct `LoggerInterface` calls.
- Before injecting `LoggerInterface`, ask whether the behavior should be an event.
- Event PHPDoc log category format: `switon.{package}.{component}.{action}`.
- Use lowercase dot notation.
- Use events when:
  - the component already uses an event dispatcher;
  - the log represents a meaningful system event; or
  - tracer or external listeners may need to react.
- Direct logger usage is acceptable only when:
  - the component is itself the logger;
  - the code is a very low-level utility; or
  - the logging is temporary debugging.

---

## Control-flow exceptions

- Use `StopFlow` only for intentional short-circuit control flow.
- Valid uses include ending a listener chain, stopping a request pipeline early, or redirect-style application exits.
- Do not use `StopFlow` to replace ordinary failures or to hide real errors.

---
