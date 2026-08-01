# Query FAQ {#query-faq}

Short answers for query building, filters, pagination, and sharding.

> **Prerequisites:** [Query Package](README.md)

## Getting Started {#getting-started}

### Do I need `QueryBuilderInterface`? {#need-query-builder}

Use it when you want a fresh query instance for each read path.

### When should I use `Table::of()`? {#use-table-of}

Use it when the table should be pinned to a specific connection.

### Does `where(array)` replace raw SQL? {#where-vs-raw}

No. It covers the common compact filter grammar. Use raw expressions only when you need them.

### What can I pass to `from()`? {#from-shape}

You can pass a table name or an entity class, depending on whether you want to read from a database-side form or an ORM-facing form.

## Pagination {#pagination}

### What does `paginate()` return? {#paginate-return}

A `PaginatorInterface` carrying page metadata and items.

On partial or empty pages, it infers `count` from the current slice and skips the extra count query; full pages still issue a `count()`.

### Which fields does `Paginator` expose? {#paginator-pages}

`toArray()` returns `page`, `size`, `count`, `pages`, and `items`. `prev` and `next` are also available as object properties (`-1` when no neighbor exists).

## Sharding {#sharding}

### Does the query package handle shard routing? {#shard-routing}

Yes. It can route reads across shards and merge results when needed.

### What happens when a query spans more than one shard? {#multi-shard}

Ordered and aggregated reads can be merged in memory. Some join or aggregate forms are still rejected when they are not shard-safe.

### Why did my sharded join fail? {#sharded-join-fail}

The join likely crossed incompatible connections or shards. Check the sharding boundary first. Before the exception is rethrown, the query layer emits `Switon\Query\Event\ShardValidationFailed` for observability.

### Why did `get()` throw? {#get-throws}

`get()` is the not-found boundary for single-row reads, so it throws `NotFoundException` when nothing matches.
