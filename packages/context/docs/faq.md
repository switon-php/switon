# Context FAQ {#context-faq}

Short answers for scoped service state, isolation modes, and common coroutine pitfalls.

> **Prerequisites:** [Context Package](README.md)

## Getting Started {#getting-started}

### When should I use Context? {#use-context}

Use Context when one long-lived service owns mutable state across multiple methods and that state must follow the current request or coroutine scope. If you only need a one-step handoff, request attributes are usually simpler.
See [Context Package](README.md) and [HTTP Request](https://github.com/switon-php/http/blob/main/docs/request.md#request).

### Why inject `Switon\Core\ContextManagerInterface`? {#inject-core-interface}

Inject the core contract in application code. The context package provides the default implementation during startup, so services stay decoupled from the package class.
See [Configuration](README.md#configuration).

### What does `getContext()` need to return? {#context-return-type}

It must declare one loadable context class, and that class must be instantiable with no required constructor arguments. Invalid declarations raise `Switon\Context\Exception\ContextException`.
See [Context Class Rules](README.md#context-class-rules).

### When do I use `ContextIsolated`? {#use-contextisolated}

Use `ContextIsolated` when child coroutines must not share temporary or resource-bound state with the parent, for example transactions or scratch buffers. Without it, child coroutines inherit the parent context.
See [Core Concepts](README.md#marker-contracts).

### What does `resetContexts()` clear? {#reset-contexts}

It clears only the non-coroutine cache on the manager instance. In long-lived workers, that means you still need to reset it when you want to stop reusing state across requests.
See [Keep in Mind](README.md#keep-in-mind) and [Design Rationale](design.md#design-rationale).
