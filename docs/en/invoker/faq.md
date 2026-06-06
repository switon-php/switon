# Invoker FAQ {#invoker-faq}

Short answers for callable execution, hooks, and dispatch boundaries.

> **Prerequisites:** [Invoker Package](README.md)

## Getting Started {#getting-started}

### When should I use the invoker entry point instead of a direct call? {#direct-call}

Use `Switon\Invoking\InvokerInterface` when the call site wants one managed final call with DI fallback, guards, or
interceptors. See [Quick Start](README.md#quick-start).

Use `Switon\Di\InvokerInterface` when you only need the lower-level callable executor without method-level hooks. Guard
and interceptor contracts live in [Invocation Package](../invocation/README.md).

### Can the invoker build arguments from request input? {#build-arguments}

No. Use [Binding Package](../binding/README.md) first when arguments still come from input, typed input, or builders.

### Which callable forms work? {#callable-shapes}

Array callables, closures, string callables, and invokable objects all work.
See [Callable Boundary](README.md#callable-boundary).

## Boundaries {#boundaries}

### Where do parameter values come from? {#parameter-values}

From explicit overrides, then defaults, then type-keyed objects, then DI fallback.
See [Argument Resolution](README.md#argument-resolution). Transport input belongs
to [Binding Package](../binding/README.md).

### Why do guards or interceptors not run on direct method calls? {#direct-hooks}

Because hooks only run through `invoke()`. Plain calls bypass the invocation pipeline.

`preHandle()` returning `false` does not skip the call; throw instead. `postHandle()` may replace the return value by
reference.

### Does invoker own CLI parsing or short-option behavior? {#cli-parsing}

No. CLI parsing belongs to `switon/cli`; invoker only runs the final callable.

### Do I need package-specific configuration? {#configuration}

No. Invoker uses the normal DI wiring and adds no option surface of its own.

## Troubleshooting {#troubleshooting}

### Why is my object parameter coming from the container? {#object-container}

That is the built-in DI fallback. If you wanted a binding pipeline first, resolve the arguments
through [Binding Package](../binding/README.md).

### How do I test code that uses invoker? {#testing}

Test business logic directly. Test guards or interceptors through `Switon\Invoking\InvokerInterface` when managed
invocation is the behavior under test.
