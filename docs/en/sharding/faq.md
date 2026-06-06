# Sharding FAQ {#sharding-faq}

Short answers for setup and common sharding issues.

> **Prerequisites:** [Sharding Package](README.md)

## Getting Started {#getting-started}

### How do I enable sharding for an entity? {#enable-sharding}

Add shard rules and include the shard key in the context.

### Does sharding create databases or tables for me? {#create-tables}

No. Sharding only resolves names.

### Why did my query touch all shards? {#why-fanout}

The shard context did not narrow to one shard.

### What is the minimum requirement for a single-shard operation? {#single-shard-minimum}

Provide the shard key field used by the rule.

## Strategies {#strategies}

### Which strategy should I start with? {#choose-strategy}

Use `modulo` for numeric ids, `crc32` or `hash` for strings, `range` for intervals, and `list` for fixed shard sets.

### Can `list` route by shard key value? {#list-strategy-routing}

No. `list` ignores the shard key value.

### What does `hash:16` mean? {#hash-default-algo}

It means the `hash` strategy uses its default algorithm with `16` shards.

### What happens when a `range` value matches no interval? {#range-no-match}

It resolves to no shard.

### What happens when a `range` shard key is `null`? {#range-null-fanout}

It resolves to all configured range shards.

### What happens when `multiple()` gets no shard on the table side? {#multiple-empty-table}

It returns an empty result, even if the connection side resolved normally.

### Can I change shard count later? {#change-shard-count}

Yes, but hash-like strategies will remap rows.

## Queries And Joins {#queries-and-joins}

### Why does a join fail even when the table names look correct? {#join-fails}

Join validation checks resolved targets, not just visible names.

### Can I use cross-shard joins at all? {#cross-shard-joins}

No. Use separate queries and merge in application code.

### What is the difference between `unique()`, `multiple()`, and `all()`? {#manager-api-difference}

`unique()` wants one shard, `multiple()` returns matched shards, and `all()` returns every configured shard.

### Why does `unique([1, 2])` not behave like one context? {#unique-numeric-array}

Because numeric arrays are treated as multiple contexts that must all resolve to the same shard.

### What happens when a context is an object? {#object-context}

The manager reads its public properties with `get_object_vars()`.

## Operations {#operations}

### How do I verify shard selection quickly? {#verify-routing}

Compare a known shard key value with the actual connection and table targets.

### What should a re-sharding rollout plan include? {#rollout-plan}

Data parity checks, monitoring, latency checks, and a rollback path.
