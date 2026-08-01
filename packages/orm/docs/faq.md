# ORM FAQ {#orm-faq}

Short answers for common adoption and usage questions.

> **Prerequisites:** [ORM Package](README.md)

## Getting Started {#getting-started}

### Should I use ORM, Query, or DB? {#orm-query-db}

Use ORM when you want typed entities and repository-first app code through repository subclasses. Use Query when you
want ad-hoc query composition without entities. Use DB when you need raw SQL or lower-level transaction control.

### Do I need to call `update()` after changing an entity? {#explicit-update}

Yes. In-memory changes do nothing until a repository write runs.

### Should I use `get()` or `find()`? {#get-vs-find}

Use `get()` when the row must exist. Use `find()` when `null` is a normal outcome.

### Should I use `first()` or `firstOrFail()`? {#vs-fail}

Use `first()` when no match is acceptable. Use `firstOrFail()` when one match must exist.

## Relations {#relations}

### Do relations lazy-load on property access? {#lazy-proxy}

No. Request relations in the repository call. See [Relation Guide](relations.md#relation-guide) for the loading shapes.

### How do I eager-load a relation? {#eager-load}

Pass relation names in repository `fields`, for example `['posts' => ['id', 'title']]`.

### Where do relation rules live? {#relation-rules}

Relation metadata lives on entity classes. Loading shape lives in the repository call.

### Which relation shapes are supported? {#supported-relations}

See [Relation Guide](relations.md#relation-guide) for the supported shapes and examples.

## Pagination {#pagination}

### How do I bind `Page` from request input? {#page-binding}

Type-hint `Switon\Orm\Page` in the action parameter. It reads `page` and `size` from the current input.

### What are the default `page` and `size` values? {#page-defaults}

`page` defaults to `1` and `size` defaults to `10`. Values below `1` fail binding, and the default `page * size` cap is
`1000`. `Switon\Orm\Attribute\PageLimit` adjusts the cap for one action.

## Sharding {#sharding}

### What happens when a write cannot target one shard? {#sharding-too-many}

The write fails fast. Provide the shard-driving fields before calling the repository method.

### Do bulk updates and deletes stay on one shard? {#bulk-shard}

No. `updateAll()` and `deleteAll()` follow query-style execution, so they can fan out across shards.

### Does ORM create shard tables for me? {#shard-schema}

No. Provision the physical schema first.

## Configuration {#configuration}

### Where do I inspect current ORM options? {#inspect-options}

Run `bash bin/console config:options 'Switon\Orm\PageResolver'` for pagination options and
`bash bin/console config:options 'Switon\Db\ClientInterface'` for DB wiring.

### Why does `fill()` ignore `null`? {#fill-null}

`fill()` treats `null` as not provided, so it does not set SQL `NULL`. Use a lower-level path if you need an explicit
null write.
