# DB FAQ {#db-faq}

Short answers for configuration, SQL clients, transactions, and common database behavior.

> **Prerequisites:** [DB Package](README.md)

## Getting Started {#getting-started}

### What should most apps start with? {#default-start}

One `default.uri`, one injected `ClientInterface`, and prepared SQL with named binds.
See [Quick Start](README.md#quick-start).

### When should I use `getTransient('default')`? {#transient-client}

Use it when one local scope must keep the same connection for a transaction or read-after-write check.
See [Transient Connections](README.md#transient-connections).

### When should I use `getTransient('auto')` or `slave`? {#transient-read-routing}

Only for read routing. `auto` picks a replica when one exists; `slave` forces the replica path when possible and falls
back to `default` when no replica exists.
See [Transient Connections](README.md#transient-connections).

### When do I need `TransactionManagerInterface`? {#transaction-manager}

Use it when raw SQL must join an ORM-led transaction scope. The transaction manager returns the already-bound client.
See [Transient Connections](README.md#transient-connections).

## Configuration {#configuration}

### How do I configure more than one database? {#multiple-connections}

Use named `Switon\Di\Factory` entries under `Switon\Db\ClientInterface`, then inject the matching property name.

### Why are reads stale right after a write? {#stale-read}

Because `fetchAll()` may route to a replica. Pin the scope with `getTransient('default')` when the next read must see
the write.

### Why did `begin()` fail on my shared client? {#begin-transient}

Transaction APIs belong to transient clients. Call `getTransient('default')` first, then `begin()`.

### How do prefixes work? {#prefixes}

Set `prefix=app_` in the URI. High-level CRUD helpers apply it automatically; manual SQL stays manual.

### Why did `update()` or `delete()` reject my condition? {#unsafe-condition}

Empty conditions and simple single-word conditions are rejected. Use a real SQL expression, or use `WHERE FALSE` for an
explicit no-op delete.

### How do I inspect the current option surface? {#inspect-options}

Run `bash bin/console config:options 'Switon\Db\ClientInterface'`.
It shows the current concrete class and top-level injected properties.

### How do I wire read replicas? {#wire-replicas}

Use a multi-URI `default.uri`. The first URI is master, the rest are replicas, and replica connections are marked
`readonly=1` automatically.
See [Connection Topology](README.md#connection-topology).

## Troubleshooting {#troubleshooting}

### Why do `update()` and `delete()` reject my condition? {#unsafe-operation}

They require a real condition to avoid accidental full-table writes. Use `updateBySql()` or `deleteBySql()` when a
broad operation is deliberate.

### What should I check first when a connection fails? {#connection-errors}

Run `bash bin/console db:info` or `bash bin/console db:ping`, then check host, credentials, pool size, and any
long-lived transient client.
