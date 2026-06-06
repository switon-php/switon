# Design Rationale {#design-rationale}
`switon/query` splits query work into predicate parsing, SQL building, shard routing, and in-memory result merging.

## See also {#see-also}

- [Query Package](README.md) — usage, concepts, and boundaries
- [Query FAQ](faq.md#query-faq) — short answers and shard questions

---

## Core Problem {#core-problem}

Query code tends to grow in three directions at once:

- filter syntax becomes hard to keep consistent
- SQL assembly becomes tied to one database-side form
- sharded reads become tangled with ordinary reads

`switon/query` keeps those concerns separate so the common read path stays fluent and the hard cases stay explicit.

## Why The Split Exists {#split}

- `ConditionBuilder` and `PredicatePipeline` turn compact filters into normalized predicates.
- `SqlBuilder` turns query clauses into SQL for one connection.
- `ShardRouting` decides which tables a query should touch.
- `ShardMerger` combines ordered, indexed, or grouped rows in memory when the query spans more than one shard.

That split keeps each part smaller and easier to replace.

## What It Deliberately Does Not Hide {#hide}

The package does not pretend that every query form is shard-safe.

Some joins, groupings, and aggregates still need to fail early instead of guessing.
That is why the design keeps validation visible instead of trying to auto-correct every case.

## Why The Public API Stays Fluent {#public-api-fluent}

The public query API is built for the common read path:

- start from a table or entity
- add filters and joins
- choose one fetch form
- paginate or aggregate when needed

That gives application code one readable path while the internal split handles parsing, SQL generation, and shard coordination behind it.
