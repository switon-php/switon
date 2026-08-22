# Invocation FAQ {#invocation-faq}

Short answers for method-level guards and interceptors.

> **Prerequisites:** [Invocation Package](README.md)

## Getting Started {#getting-started}

### How do I block a method? {#block-a-method}

Add an attribute that implements `Switon\Invocation\Attribute\GuardInterface` and throw from its `handle()` method. See [Quick Start](README.md#quick-start).

### Where do the hooks actually run? {#where-hooks-run}

Through [Invoker](https://github.com/switon-php/invoker/blob/main/docs/README.md). A direct call such as `$service->deleteAction(1)` does not run guards or interceptors.

### Do I have to implement every interceptor method? {#partial-hooks}

No. Extend `Switon\Invocation\Attribute\Interceptor` and override the hook you need.

## Behavior {#behavior}

### Does `preHandle()` returning false skip the method? {#prehandle-false}

No. Throw to block the call. `false` does not skip the target.

### Can a guard take the current request? {#guard-dependencies}

Yes. `handle()` can declare typed parameters the invoker resolves, such as `Switon\Http\RequestInterface`. Constructor arguments still come from the attribute usage site.

### Can an interceptor replace the return value? {#replace-return}

Yes. `postHandle()` receives the return value by reference and may replace it after the target succeeds.

### What runs when the target throws? {#on-exception}

`onException()` on each interceptor. It can observe the throwable or rethrow it.
