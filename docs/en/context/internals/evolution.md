# Context Implementation Deep Dive {#context-evolution}

How Switon achieves transparent request isolation that works identically in both FPM and Swoole.

For basic usage, see [Context Package](../README.md).

## Introduction {#introduction}

This document explains why context exists, how the mechanism evolves step by step, and how Switon keeps behavior
consistent across FPM and Swoole.

Read it when you want implementation rationale rather than API usage.

---

## Why Context Matters {#why-context-matters}

Why context isolation matters in long-running runtimes.

### The PHP-FPM World {#php-fpm-world}

Traditional PHP-FPM runs one request per process. The key property is automatic isolation.

```php
class RequestState
{
    protected array $attributes = [];

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
```

In FPM, each request has its own memory space. When the request ends, state is released with the process lifecycle. One
request cannot read another request's state.

### The Swoole Challenge {#the-swoole-challenge}

Swoole uses a long-lived process with many concurrent coroutines. This improves throughput and resource reuse:

- No process creation overhead
- Persistent connections (database, Redis, etc.)
- True async I/O
- Ability to handle 10,000+ concurrent connections

But it introduces a fundamental problem: **shared memory**.

```php
// Same RequestState class from above

// Request 1 (Coroutine #1): $request->setAttribute('user_id', 123);
// Request 2 (Coroutine #2): $request->setAttribute('user_id', 456);
// Request 1 reads: $request->getAttribute('user_id'); // Returns 456! ❌
```

Multiple coroutines can share the same `RequestState` instance, so they also share `$attributes`. In production, this
appears as cross-request state leakage.

The core question is: how do we keep FPM-like isolation in Swoole without runtime-specific business code?

### The Ideal Solution {#the-ideal-solution}

What we want is a context system that:

- **Transparent** - Same code works in both FPM and Swoole, no `if (swoole_running())` checks
- **Automatic** - No manual coroutine ID tracking or context passing
- **Safe** - Impossible to accidentally share state between requests
- **Fast** - Minimal overhead, no performance penalty
- **Flexible** - Support inherited sharing, isolated child state, and adapter-owned connection lifetime markers
- **Zero configuration** - Works out of the box, no setup required
- **Developer-friendly** - Clean API, good IDE support, easy to debug
- **Centralized and inspectable** - All context state aggregated in dedicated classes, easy to audit and debug

Switon's context system is designed to satisfy these goals.

---

## Overview {#overview}

This is a walkthrough, not a line-by-line source map. It starts from `Coroutine::getContext()` and adds namespacing,
context classes, discovery, and dual-runtime behavior step by step.

For production behavior, use:

- [Context package README](../README.md) — `ContextAware`, `ContextManager`, isolation modes, inheritance.
- [Context design](../design.md#design-rationale) — storage model and runtime behavior.

---

## The Fundamental Problem {#problem}

The core concurrency problem this design solves.

### FPM: Natural Isolation {#fpm-natural-isolation}

In traditional PHP-FPM, each request runs in a separate process:

```php
class RequestState
{
    protected array $attributes = [];

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }
}

// Request 1 (Process A): $request->attributes = ['user_id' => 123]
// Request 2 (Process B): $request->attributes = ['user_id' => 456]
// ✅ No conflict: Different processes, different memory
```

### Swoole: Shared Memory Problem {#swoole-memory-problem}

In Swoole, multiple requests run as coroutines in the same process:

```php
// Same RequestState class

// Request 1 (Coroutine #1): $request->attributes = ['user_id' => 123]
// Request 2 (Coroutine #2): $request->attributes = ['user_id' => 456]
// ❌ Request 1 now sees user_id = 456 (data corruption!)
```

**The challenge:** How do we isolate per-request state without changing application code?

---

## Evolution: From Naive to Production {#evolution}

This section builds a context system step by step and records what breaks at each step.

Each step fixes one problem, then exposes the next constraint. This progression explains why the production design
includes centralized management, isolation modes, and cleanup hooks instead of a single ad-hoc helper.

By the end, you can map each production feature back to the concrete failure mode it prevents.

**The 8 steps:**

1. Basic Swoole Context (namespace collision)
2. Handle multiple instances with `spl_object_id()` (solve collision)
3. Support multiple properties (Context class aggregates state)
4. Simplify access (`__get()` magic method)
5. Auto-detect (naming convention)
6. Handle inheritance (search up class hierarchy)
7. Use type system (reflection reads return type)
8. Support FPM (check `Runtime::isCoroutineEnabled()`)

### Step 1: Basic Swoole Context {#step1}

`Coroutine::getContext()` provides coroutine-local storage (`ArrayObject`). It solves base isolation, but direct use
quickly becomes hard to maintain.

**Simple usage:**

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $context[$name] = $value;
    }

    public function getAttribute(string $name): mixed
    {
        $context = \Swoole\Coroutine::getContext();
        return $context[$name] ?? null;
    }
}

// Request 1 (Coroutine #1): Gets its own context storage
// Request 2 (Coroutine #2): Gets a different context storage
// ✅ Isolated: Each coroutine has separate data
```

This first version still has four problems:

**Problem 1: Namespace collision**

Different components using the same key names will corrupt each other's data:

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $context[$name] = $value;  // Uses string key
    }
}

class SessionState
{
    public function set(string $key, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $context[$key] = $value;  // Also uses string key - collision! ❌
    }
}

// Both components corrupt each other's data
$request = new RequestState();
$session = new SessionState();
$request->setAttribute('user_id', 123);  // Sets 'user_id'
$session->set('user_id', 456);           // Overwrites 'user_id'!
$request->getAttribute('user_id');       // Returns 456 instead of 123 ❌
```

**Problem 2: Multiple instances share state**

Even multiple instances of the same class will interfere with each other:

```php
$request1 = new RequestState();
$request2 = new RequestState();
$request1->setAttribute('user_id', 123);  // Sets 'user_id'
$request2->setAttribute('user_id', 456);  // Overwrites same 'user_id'!
$request1->getAttribute('user_id');       // Returns 456 instead of 123 ❌
```

**Problem 3: Only works in Swoole**

`Coroutine::getContext()` doesn't exist in FPM, so this code will crash in traditional PHP environments.

**Problem 4: No type safety**

String keys provide no IDE autocomplete or type checking - you won't know what keys are available or what types they
should store.

**Root cause:** All components share the same flat key-value storage without any isolation mechanism.

### Step 2: Handle Multiple Instances and Namespacing {#step2}

The first version shares one flat storage map. Different classes can overwrite the same key, and multiple instances of
one class also collide.

**The solution:** Use each object's unique ID as the key.

#### PHP Feature: `spl_object_id()` {#php-object-id}

PHP provides a built-in function that returns a unique integer for every object instance:

```php
$obj1 = new stdClass();
$obj2 = new stdClass();

echo spl_object_id($obj1);  // e.g., 1
echo spl_object_id($obj2);  // e.g., 2

// Same object = same ID
$obj3 = $obj1;
echo spl_object_id($obj3);  // 1 (same as $obj1)
```

**Key properties:**

- **Unique per instance** - each live object gets a different ID
- **Stable** - ID stays stable for the object lifetime
- **Fast** - native O(1) lookup
- **Integer** - efficient array key
- **Reusable after destroy** - only safe when storage tied to that ID is also cleaned up with the owner lifecycle

This gives us automatic namespacing without any manual coordination between components.

#### Implementation {#implementation}

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);  // e.g., 42
        $context[$oid][$name] = $value;
    }

    public function getAttribute(string $name): mixed
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);
        return $context[$oid][$name] ?? null;
    }
}

// Now works with multiple instances
$request1 = new RequestState();  // Object ID: 42
$request2 = new RequestState();  // Object ID: 87
$request1->setAttribute('user_id', 123); // $context[42]['user_id'] = 123
$request2->setAttribute('user_id', 456); // $context[87]['user_id'] = 456
```

This solves two issues at once:

**Multiple instances work correctly** - each instance has unique object ID

**Namespace collision solved** - different components have different object IDs

Namespace collision is avoided because each component instance writes to its own object-ID bucket:

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this); // e.g., 42
        $context[$oid][$name] = $value;
    }
}

class SessionState
{
    public function set(string $key, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this); // e.g., 87 (different!)
        $context[$oid][$key] = $value;
    }
}

// No collision - different object IDs
$request = new RequestState(); // Object ID: 42
$session = new SessionState(); // Object ID: 87
$request->setAttribute('user_id', 123); // $context[42]['user_id'] = 123
$session->set('user_id', 456);          // $context[87]['user_id'] = 456
$request->getAttribute('user_id');      // Returns 123 ✅ (reads $context[42])
```

**Problems solved:**

- Multiple instances work correctly
- Namespace collision solved

**Remaining problems:**

- Still doesn't work in FPM
- State is scattered throughout the code (hard to audit)
- Repetitive context access code
- **Impossible to audit** - In a 1000-line class, how do you know what context keys it uses? You'd have to read every
  line to find all `setAttribute()` calls

The remaining issue is discoverability: state keys are still spread across methods.

### Step 3: Support Multiple Properties {#step3}

Instance isolation now works, but state definition is still scattered. Real components usually track multiple fields (
user, session, attributes), and string keys hide that model.

The solution is a dedicated context class that declares state in one place.

```php
class RequestStateContext
{
    public ?User $user = null;
    public array $attributes = [];
    public string $sessionId = '';
}

class RequestState
{
    public function setUser(User $user): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);

        if (!isset($context[$oid])) {
            $context[$oid] = new RequestStateContext();
        }

        $context[$oid]->user = $user;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);

        if (!isset($context[$oid])) {
            $context[$oid] = new RequestStateContext();
        }

        $context[$oid]->attributes[$name] = $value;
    }
}
```

**Why use a separate context class?**

1. **Type safety** - No more magic strings like `'user_id'` or `'session_id'`
2. **IDE support** - Autocomplete works, typos are caught at compile time
3. **Clear structure** - Easy to see what state a component needs at a glance
4. **Separation of concerns** - Context (state) is separate from logic (methods)
5. **Easy to audit** - All state declared in one place

This is especially useful for large classes:

```php
// ❌ String keys - must read entire class to find all state
class RequestState  // 1000 lines
{
    public function method1(): void
    {
        // $this->context['user_id'] = ...  // Line 50
    }

    public function method2(): void
    {
        // $this->context['session_id'] = ...  // Line 300
    }

    public function method3(): void
    {
        // $this->context['attributes'] = ...  // Line 800
    }

    // What state does this class use? You must read all 1000 lines!
}

// ✅ Context class - all state declared in one place
class RequestStateContext  // 5 lines
{
    public ?User $user = null;
    public array $attributes = [];
    public string $sessionId = '';
    // All state visible at a glance!
}

class RequestState  // 1000 lines
{
    // Business logic only, no state declarations scattered around
}
```

Combined with switoners, code review becomes more systematic:

In Swoole, not all properties are dangerous. Switon uses markers to make code review easier:

- **`#[Autowired]`** - Dependency injection (typically config, stateless services, or services that keep mutable state
  in a context object)
- **`#[Communal]`** (`Switon\Core\Attribute\Communal`) - Review marker: property vetted as safe to share on a long-lived
  service instance
- **Context class** - Per-scope state (isolated by `ContextManager` in production)
- **No marker** - Treat as needing review in Swoole-style deployments

> **For the `#[Communal]` marker, see [Core Package](../../core/README.md#context-state).**

The context class is a data container. Public properties are enough because the owner component controls access.

**Problems solved:**

- Multiple properties per instance
- Type-safe (no magic strings)
- Better IDE support
- **Easy to audit** - all state declared in one place

**Remaining problems:**

- Still doesn't work in FPM
- Repetitive context access code (every method needs the same 5 lines)

### Step 4: Simplify Context Access {#step4}

Without abstraction, each method repeats the same context-access boilerplate.

The solution is to hide storage lookup behind `__get()`.

The `__get()` method is called when you access a property that doesn't exist. We can use this to make `$this->context`
automatically return the correct context object:

```php
class RequestStateContext
{
    public ?User $user = null;
    public array $attributes = [];
}

/**
 * @property-read RequestStateContext $context
 */
class RequestState
{
    public function __get(string $name)
    {
        if ($name === 'context') {
            $ctx = \Swoole\Coroutine::getContext();
            $oid = spl_object_id($this);

            if (!isset($ctx[$oid])) {
                $ctx[$oid] = new RequestStateContext();
            }

            return $ctx[$oid];
        }

        throw new \Exception("Property {$name} not found");
    }

    public function setUser(User $user): void
    {
        $this->context->user = $user;  // ✅ Clean!
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->context->attributes[$name] = $value;  // ✅ Clean!
    }
}
```

Flow:

1. When you write `$this->context`, PHP sees there's no `$context` property
2. PHP calls `__get('context')`
3. `__get()` retrieves (or creates) the context object from Swoole's storage
4. The context object is returned
5. You can then access its properties: `$this->context->count`

`@property-read` tells the IDE that `$context` exists and which type it returns.

**Why this is better:**

- Business logic is clear and readable
- No boilerplate in every method
- Impossible to forget the context access code
- IDE understands the type of `$this->context`

**Problems solved:**

- Clean, simple API
- Context access hidden from business logic
- Less code to maintain

**Remaining problems:**

- Still doesn't work in FPM
- Every class needs to implement `__get()`
- Context class name is hardcoded

### Step 5: Auto-Detect Context Class {#step5}

Step 4 still hardcodes `new RequestStateContext()`, so each component needs custom wiring.

The next step is a naming convention: append `Context` to the class name.

```php
class RequestStateContext
{
    public ?User $user = null;
}

/**
 * @property-read RequestStateContext $context
 */
class RequestState
{
    public function __get(string $name)
    {
        if ($name === 'context') {
            $ctx = \Swoole\Coroutine::getContext();
            $oid = spl_object_id($this);

            if (!isset($ctx[$oid])) {
                // Auto-detect: ClassName + "Context"
                $contextClass = static::class . 'Context';

                if (!class_exists($contextClass)) {
                    throw new \Exception("Context class {$contextClass} not found");
                }

                $ctx[$oid] = new $contextClass();
            }

            return $ctx[$oid];
        }

        throw new \Exception("Property {$name} not found");
    }

    public function setUser(User $user): void
    {
        $this->context->user = $user;
    }
}

// Auto-resolved
$request = new RequestState();  // Auto-finds RequestStateContext
$request->setUser($user);
```

Flow:

1. When `$this->context` is accessed, `__get('context')` is triggered
2. `__get()` constructs the context class name: `RequestState` + `Context` = `RequestStateContext`
3. Checks if the class exists
4. Creates an instance and stores it
5. Future accesses return the same instance

Pros:

- Automatic - no manual configuration
- Convention-based - predictable naming
- Minimal convention - append `Context`

Limitations:

- Inflexible - context class must follow naming convention
- No type safety - IDE doesn't know the exact type
- Refactoring-unfriendly - renaming breaks the convention
- Magic strings - relies on string concatenation

**Remaining problems:**

- Still doesn't work in FPM
- Doesn't handle inheritance properly
- Naming convention is inflexible

### Step 6: Handle Inheritance {#step6}

A naming convention alone is fragile with inheritance. Parent class usage works, but child classes can fail when their
derived context class does not exist.

- Using `RequestState` directly works
- Using `ExtendedRequestState` crashes with "Class not found"
- No logic changes in child class - only inheritance
- Error points to missing derived context class

**The problem:**

```php
class RequestState
{
    // Uses naming convention: RequestState + "Context" = RequestStateContext
}

class RequestStateContext
{
    public ?User $user = null;
}

// Parent usage
$request = new RequestState();
$request->setUser($user);

// Child usage can fail without a derived context class
class ExtendedRequestState extends RequestState
{
    // Naming convention looks for: ExtendedRequestState + "Context" = ExtendedRequestStateContext
    // But ExtendedRequestStateContext doesn't exist!
}

$extended = new ExtendedRequestState();
$extended->setUser($user);  // Fatal error: ExtendedRequestStateContext not found
```

Why this fails in practice:

1. **Parent works, child breaks** - behavior changes after simple inheritance
2. **Error surface is indirect** - failure appears inside `__get()`
3. **Convention is implicit** - parent class does not state this requirement clearly

**The solution:** Search up the class hierarchy to find the first context class that exists.

```php
class RequestStateContext
{
    public ?User $user = null;
}

/**
 * @property-read RequestStateContext $context
 */
class RequestState
{
    public function __get(string $name)
    {
        if ($name === 'context') {
            $ctx = \Swoole\Coroutine::getContext();
            $oid = spl_object_id($this);

            if (!isset($ctx[$oid])) {
                // Search up the class hierarchy
                $class = static::class;
                $contextClass = null;

                do {
                    $candidate = $class . 'Context';
                    if (class_exists($candidate)) {
                        $contextClass = $candidate;
                        break;
                    }
                    $class = get_parent_class($class);
                } while ($class !== false);

                if ($contextClass === null) {
                    throw new \Exception("Context class not found for " . static::class);
                }

                $ctx[$oid] = new $contextClass();
            }

            return $ctx[$oid];
        }

        throw new \Exception("Property {$name} not found");
    }

    public function setUser(User $user): void
    {
        $this->context->user = $user;
    }
}

// Child class reuses parent context
class ExtendedRequestState extends RequestState
{
    // No need to create ExtendedRequestStateContext
    // Automatically finds and uses RequestStateContext
}

// Works
$extended = new ExtendedRequestState();
$extended->setUser($user);  // Uses RequestStateContext from parent
```

**How the search works:**

1. `ExtendedRequestState` is instantiated
2. First access to `$this->context` triggers `__get()`
3. Search starts with `ExtendedRequestState`:
    - Try `ExtendedRequestStateContext` - doesn't exist
    - Move to parent: `get_parent_class('ExtendedRequestState')` = `'RequestState'`
4. Try `RequestStateContext` and use it when available
5. Creates and returns `new RequestStateContext()`

When a child needs extra state:

Sometimes a child class needs additional state:

```php
class ExtendedRequestStateContext extends RequestStateContext
{
    public string $sessionId = '';  // Additional state
}

class ExtendedRequestState extends RequestState
{
    // Now ExtendedRequestStateContext exists, so it will be used
}
```

**Problems solved:**

- Inheritance works correctly
- Child classes can reuse parent context
- Child classes can override with their own context

**Remaining problems:**

- Still doesn't work in FPM
- Still uses naming convention (inflexible, no type safety)

### Step 7: Use Type System for Context Discovery {#step7}

Naming convention remains implicit and rigid. A better approach is explicit type declaration via method return type.

Use `getContext()` return type and reflection to discover the context class.

```php
class RequestStateContext
{
    public ?User $user = null;
}

/**
 * @property-read RequestStateContext $context
 */
class RequestState
{
    public function __get(string $name)
    {
        if ($name === 'context') {
            $ctx = \Swoole\Coroutine::getContext();
            $oid = spl_object_id($this);

            if (!isset($ctx[$oid])) {
                // Use reflection to read return type of getContext()
                $method = new \ReflectionMethod($this, 'getContext');
                $returnType = $method->getReturnType();

                if (!$returnType || $returnType->isBuiltin()) {
                    throw new \Exception("getContext() must have a class return type");
                }

                $contextClass = $returnType->getName();
                $ctx[$oid] = new $contextClass();
            }

            return $ctx[$oid];
        }

        throw new \Exception("Property {$name} not found");
    }

    // Declare return type - this is what reflection reads
    public function getContext(): RequestStateContext
    {
        return $this->context;
    }

    public function setUser(User $user): void
    {
        $this->getContext()->user = $user;
    }
}
```

Flow:

1. Component declares `getContext()` with return type: `RequestStateContext`
2. When `$this->getContext()` is called, it accesses `$this->context`
3. `$this->context` triggers `__get('context')`
4. `__get()` uses reflection to read the return type of `getContext()`
5. Creates instance of that class and stores it
6. Returns the context instance

Inheritance behavior:

PHP's `ReflectionMethod` automatically searches up the inheritance chain:

```php
class ExtendedRequestState extends RequestState
{
    // No need to redeclare getContext()
    // Automatically uses parent's RequestStateContext
}
```

When a child needs a different context type:

```php
class ExtendedRequestStateContext extends RequestStateContext
{
    public string $sessionId = '';
}

class ExtendedRequestState extends RequestState
{
    // Override to use different context
    public function getContext(): ExtendedRequestStateContext
    {
        return $this->context;
    }
}
```

What this improves:

- **Type-safe** - Compiler checks the return type exists
- **IDE support** - Autocomplete, go-to-definition, refactoring all work
- **Flexible** - Context class can have any name (not just `XxxContext`)
- **Explicit** - The relationship is declared in code
- **Refactoring-friendly** - Rename detection works automatically
- **Inheritance-friendly** - Child classes automatically inherit parent's context

Problems solved:

- No naming convention required
- Type-safe and IDE-friendly
- Flexible naming
- Inheritance still works

Remaining problems:

- Still doesn't work in FPM

### Step 8: Support Both FPM and Swoole {#step8}

The previous step still assumes coroutine APIs exist. FPM needs a different storage backend.

> **Note:** Switon’s `ContextManager` branches on `Runtime::isCoroutineEnabled()` (a bootstrap flag set by the kernel),
> not on the extension merely being loaded, and coroutine storage paths assume a real coroutine context when that flag
> is
> on.

In the sketch, use coroutine storage when Swoole is active; otherwise store context on the object for FPM.

#### Implementation {#step8-implementation}

Just add one check to Step 7's code:

```php
class RequestState
{
    protected ?object $_context = null;  // FPM storage

    public function __get(string $name)
    {
        if ($name === 'context') {
            // Check whether coroutine storage should be used
            if (\Switon\Core\Runtime::isCoroutineEnabled()) {
                // Coroutine runtime: use coroutine context
                $ctx = \Swoole\Coroutine::getContext();
                $oid = spl_object_id($this);

                if (!isset($ctx[$oid])) {
                    $ctx[$oid] = $this->createContext();
                }

                return $ctx[$oid];
            }
            // FPM: Store directly on object
            else {
                if ($this->_context === null) {
                    $this->_context = $this->createContext();
                }
                return $this->_context;
            }
        }

        throw new \Exception("Property {$name} not found");
    }

    // createContext() and getContext() same as Step 7
    // ...
}
```

Key idea: keep one accessor but switch storage backend by runtime mode. In production, this is centralized in
`ContextManager` with `Runtime::isCoroutineEnabled()`.

Runtime behavior:

In coroutine runtime:

1. runtime mode allows coroutine-local storage
2. use `Coroutine::getContext()` for per-coroutine storage
3. Each coroutine gets isolated context

In FPM:

1. `extension_loaded('swoole')` returns `false`
2. Store context directly in `$_context` property
3. Each request runs in separate process, so property is naturally isolated

What this gives:

- **Same component API** in both environments
- **Low FPM overhead** - direct property storage
- **Automatic isolation path** - process isolation in FPM, coroutine isolation in Swoole
- **No per-component runtime wiring**

Problems solved:

- Works in both FPM and Swoole
- Zero overhead in FPM (direct property access)
- Automatic runtime detection
- No configuration needed

This solves runtime compatibility, but production still needs centralized controls:

Remaining problems:

- **Boilerplate code** - Every class needs to implement `__get()`, `getContext()`, and reflection logic
- **No centralized management** - Can't inspect all active contexts for debugging
- **No cleanup mechanism** - Context objects may leak memory in long-running processes
- **Single isolation strategy** - What if some contexts need different isolation modes?
- **No monitoring** - Can't track context creation, access patterns, or performance
- **Hard to test** - Context logic mixed with business logic
- **Inheritance issues** - Child coroutines always inherit parent context (not always desired)
- **Connection-scoped state** - Some state needs to persist across coroutine lifecycle

Example problems:

```php
// Problem 1: Some state should not be inherited
class TransactionContext
{
    public string $transactionId;  // Shared across child coroutines: wrong transaction scope
}

// Problem 2: Can't debug context state
// How do you inspect all active contexts?
// How do you know which components have context?

// Problem 3: Boilerplate repeated everywhere
class RequestState { /* __get(), getContext(), createContext() */ }
class SessionState { /* __get(), getContext(), createContext() */ }
class CacheState { /* __get(), getContext(), createContext() */ }
// Same code copied 100 times!
```

The evolution explains the core mechanism; production implementation adds framework-level management.

See [Context Package](../README.md) for the usage guide and architecture.

---

## Next {#next}

- [Context Package](../README.md) — public usage, isolation modes, and common pitfalls
- [Design Rationale](../design.md#design-rationale) — stable storage and lifecycle behavior
- [Context Internals](README.md) — when to read this folder and available notes
