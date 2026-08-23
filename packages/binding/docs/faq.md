# Binding FAQ {#binding-faq}

Short answers for setup, configuration, and common binding issues.

> **Prerequisites:** [Binding Package](README.md)

## Getting Started {#getting-started}

### What is the difference between binding and invoker? {#binding-vs-invoker}

**Binding** builds the argument list. **Invoker** executes the callable.

### Do I need binding if I already know the callable? {#already-know-callable}

You need binding only when you still want the framework (or tests) to build arguments from input, builders, container state, or defaults.

### Where do parameter values come from? {#parameter-sources}

- Pre-fill hooks
- `ArgumentResolvable`
- `#[ResolvedBy]`
- **Container fallback** for unresolved objects
- **Scalars** through `InputInterface`
- Defaults and validation

Note: **`#[ShortOption]`** belongs to the **CLI option parser**, not `switon/binding`.
**`RequestBody`** and related attributes live in the **HTTP package**, but hydration uses binding.

### When should I use `InputBinderInterface`? {#when-inputbinder}

Use it when one resolver already has an **array payload** and needs a shared engine for **typed input hydration**.

## Builders and Resolvers {#builders-and-resolvers}

### When should I use `#[ResolvedBy]`? {#when-resolvedby}

Use it when **one concrete type** should always construct the **same way**.

### Why do the examples use `Page`? {#why-page}

Because `Switon\Orm\Page` is the simplest real Switon type that shows the pattern: **one stable rule lives on the type**. It is a companion-package example, not a class defined inside `switon/binding`. If your type has that kind of rule, `#[ResolvedBy]` is usually the right place for it.

### How do I copy the `Page` pattern for my own type? {#copy-page-pattern}

Put `#[ResolvedBy(...)]` on the class, implement `ValueResolverInterface`, read needed input or services in that resolver, then return the finished value object.

### When should I let the type build itself? {#when-argumentresolvable}

Use **`ArgumentResolvable`** when the construction rule belongs to the **value type** itself.

### Why is my object parameter coming from the container instead of my builder? {#object-container}

The type-owned path did not match. **Interface** types or a missing **`#[ResolvedBy]`** fall through to **container** resolution.

### How do I change scalar sourcing for many parameters? {#change-scalar-sourcing}

Replace or extend the **default scalar policy** only when you need **one broader, app-wide** change.

## Troubleshooting {#troubleshooting}

### Can my resolver return `null` as the final value? {#resolver-null}

Not for the default resolver interfaces — there, **`null` means unresolved**. **`ArgumentResolvable`** may return a real final `null` when the **value type** allows it.

### Why can’t I inject `null` by index in pre-fill hooks? {#null-by-index}

Index slots start as `null`, so index-based `null` looks the same as “not set”. **Use parameter names** when pre-filling `null`.

### Why do I get “Too many positional arguments”? {#too-many-positional}

Positional binding rejects extra values unless the **last unresolved scalar parameter** is typed as `array`.

### How does bare `--` affect array parameters? {#double-dash-trailing}

When input implements `PositionalInputInterface` and argv contains bare `--`, `getTrailing()` holds the tokens after it. The last unbound `array` parameter receives that list only; unbound optional scalars do not consume trailing tokens. Without `--`, that array still takes the remaining positionals.

### Does binding support union or intersection parameter types? {#union-intersection}

No. `ArgumentsBinderInterface` supports **simple named parameter types** only.

### How should I test binding-heavy code? {#testing}

Test business logic by calling methods with **explicit arguments**. Exercise binding itself through binding, HTTP, or CLI suites only when **argument construction** is what you mean to test.
