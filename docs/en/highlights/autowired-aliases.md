# Autowired named slots {#autowired-aliases}

Switon treats multi-instance dependency wiring as a framework problem, not as repeated local naming discipline inside business code.

**Key points:**
- `#[Autowired]` keeps the dependency declaration in code
- the property name becomes the stable slot name
- `Switon\Di\Factory` expands one contract into `Type#name` service IDs
- `Switon\Di\NamedLookupInterface` stays as the explicit runtime path when the target name is only known dynamically

## The scattered problem {#the-scattered-problem}

Applications often need more than one instance of the same capability:

- one `Switon\Redis\ClientInterface` for cache and another for session
- one mail transport for transactional mail and another for local testing
- one database-like contract with different named connections or roles

Without one framework pattern, teams usually fall back to one of these:

- inject the container and fetch different IDs inline
- hard-code string service IDs through the app
- invent local wrapper services only to rename instances
- push instance-selection details into business services that do not own wiring

That works, but it spreads instance naming into code that should only describe business dependencies.

## The Switon pattern {#the-switon-pattern}

Switon keeps the common case declarative:

- the type says what capability is needed
- the property name says which stable slot that capability should fill
- configuration maps that slot to a concrete named instance

This is why these examples stay small:

```php
use Switon\Core\Attribute\Autowired;
use Switon\Redis\ClientInterface;

class MyService
{
    #[Autowired] protected ClientInterface $cache;
    #[Autowired] protected ClientInterface $session;
    #[Autowired] protected ClientInterface $redis;
}
```

With `Switon\Di\Factory`, those properties resolve through:

- `Switon\Redis\ClientInterface#cache`
- `Switon\Redis\ClientInterface#session`
- plain `Switon\Redis\ClientInterface` as the default fallback for `$redis`

The important point is not the string format by itself. The important point is that stable slot choice stays in one place and does not leak into business flow.

## Why this is a highlight {#highlight}

This pattern solves a recurring application problem with one default rule:

- **business code stays semantic** — `$cache` and `$session` describe role, not container plumbing
- **configuration owns instance selection** — the app can retarget a slot without rewriting service logic
- **dynamic lookup stays explicit** — `Switon\Di\NamedLookupInterface` exists for real runtime selection instead of overloading normal injection

That is a stronger result than "property injection is convenient." It standardizes how one contract can safely fan out into several stable application roles.

## Boundaries {#boundaries}

This pattern is best when the slot is stable in code.

Use property-name slots when:

- one service always needs the same named backend
- the slot name is part of the service's stable role
- configuration should choose the concrete instance

Use `Switon\Di\NamedLookupInterface` instead when:

- the target name comes from runtime input
- the target name changes inside a loop or dispatch table
- the service is intentionally doing late selection

If only one instance exists, use plain type injection and let the default resolution path stay simple.

## See also {#see-also}

- [DI Package](https://github.com/switon-php/di/blob/main/docs/README.md) — DI overview
- [Autowired](https://github.com/switon-php/di/blob/main/docs/autowired.md#autowired) — resolution order and property injection rules
- [Factory](https://github.com/switon-php/di/blob/main/docs/factory.md#factory) — named instance registration and aliasing
- [DI Design Rationale](https://github.com/switon-php/di/blob/main/docs/design.md#design-rationale) — why Switon keeps instance selection explicit
