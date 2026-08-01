# Design Rationale {#design-rationale}

Why Switon DB keeps raw SQL on explicit boundaries instead of letting each app invent its own style.

## See also {#see-also}

- [DB Package](README.md) — usage, configuration, and routing
- [FAQ](faq.md#db-faq) — common questions and troubleshooting
- [DB internals](internals/README.md) — identifier quoting and transaction error handling
- [ORM Package](../../orm/docs/README.md) — repository-led writes and `TransactionManagerInterface` scopes

---

## Stateless By Default {#stateless-by-default}

The shared `Switon\Db\ClientInterface` borrows a connection per call. Ordinary reads and writes stay simple, work
cleanly with pooling, and do not hide transaction state inside services.

That keeps the common path easy to reason about and makes read/write routing possible without turning every service into
a connection owner.

## Explicit Pinning For Local Scopes {#explicit-local-scopes}

Transactions and read-after-write checks need one connection for one local scope. `getTransient('default')` makes that
boundary explicit.

This is the part that would otherwise become ambiguous: a shared client looks convenient, but it is too easy to mix
stateless calls with connection-bound work if the pin is hidden.

## Separate ORM Transaction Scopes {#separate-transaction-scopes}

`Switon\Db\TransactionManagerInterface` exists for ORM-led scopes. When raw SQL must join the already-bound client, the
transaction manager returns that client instead of guessing later.

This keeps ORM coordination and direct SQL on different contracts while still letting them meet at one transaction
boundary.

## Safe Writes And Portable SQL {#safe-portable-sql}

Empty write conditions fail fast, and `[identifier]` notation keeps manual SQL portable across supported drivers.

That makes broad writes look deliberate in code and keeps SQL text from drifting toward one driver's quoting style.

## URI-Driven Routing {#uri-driven-routing}

One URI means one topology; multiple URIs make the first URI the master and the rest replicas.

That is smaller than a custom routing DSL and still covers the common deployment shapes: one database, a pinned
transaction client, or a simple master-plus-replica setup.

## Events Over Wrappers {#events-over-wrappers}

DB lifecycle events cover query and transaction monitoring without forcing tracing code into business services.

Use listeners or collectors such as `SqlCollector` when you need metrics, debugging, or observability around SQL
execution.
