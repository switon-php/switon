# Query Package {#query-package}

`switon/query` builds read SQL with fluent filters, joins, pagination, and shard-aware execution. Choose it for read paths and SQL composition; ORM still owns writes and transaction-heavy mutation flows.

**Key features:**

- **Fluent reads:** chain `select`, `where`, `groupBy`, `orderBy`, and `limit` on one query object.
- **Compact filters:** `where(array)` covers comparison, range, `IN`, pattern, and raw forms.
- **Fetch helpers:** `first()`, `get()`, `all()`, `value()`, `values()`, `exists()`, `count()`, and aggregates.
- **Pagination:** `paginate()` returns `Switon\Query\PaginatorInterface` with page metadata and items.
- **Shard-aware execution:** route reads across shards and merge ordered or grouped rows when needed.

---

## Installation {#installation}

**Requirements:** PHP 8.3+, `switon/core`, `switon/db`, `switon/di`, `switon/sharding`

```bash
composer require switon/query
```

---

## Quick Start {#quick-start}

Inject `QueryBuilderInterface` for a fresh query, then chain filters.

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Query\QueryBuilderInterface;

final class UserFinder
{
    #[Autowired] protected QueryBuilderInterface $queryBuilder;

    public function activeUsers(): array
    {
        return $this->queryBuilder->from('users')
            ->where(['status' => 'active'])
            ->orderBy(['name' => 'ASC'])
            ->limit(10)
            ->all();
    }
}
```

---

## Core Concepts {#core-concepts}

### Query Builder {#query-builder}

`Switon\Query\QueryBuilderInterface::from()` creates a fresh `QueryInterface` for one read path. Pass a table name or an entity class FQCN; detection is by format.

### Where {#where}

`where(array)` is the daily read path for compact filters on the query object.

Common forms:

- `['status' => 'active']` for equality
- `['status' => ['active', 'pending']]` for `IN`
- `['status!=' => ['disabled', 'deleted']]` for `NOT IN`
- `['age>=' => 18]`, `['age<=' => 65]`, `['age>' => 18]`, `['age<' => 65]`
- `['created_at~=' => ['2025-01-01', '2025-12-31']]` for `BETWEEN`
- `['score%=' => [10, 3]]` for modulo checks
- `['name*=' => 'john']`, `['name^=' => 'adm']`, `['name$=' => '.com']`
- `['name,email*=' => 'john']` for matching multiple fields with `OR`
- `[0 => 'status = 1']` for a raw expression

Use the fluent helpers when the filter form is clearer that way:

- `whereEq()`, `whereCmp()`, `whereBetween()`
- `whereIn()`, `whereNotIn()`, `whereRaw()`
- `whereContains()`, `whereStartsWith()`, `whereEndsWith()`
- `whereNull()`, `whereNotNull()`

The same grammar also works in `ConditionBuilderInterface::where()`.

### Query {#query}

`Switon\Query\QueryInterface` is the main read contract: fluent filters, joins, fetch helpers, pagination, aggregates, and shard-aware execution. Array entries in `select()` whose value is an array are treated as `with()` relation calls.

### Tables {#tables}

`Switon\Query\Table` pairs a table name with a connection name. Use `Table::of('users', 'replica')` when a query needs an explicit connection target instead of the default.

### Pagination {#pagination}

`paginate(int $page, int $size)` returns `PaginatorInterface` with `page`, `size`, `count`, `pages`, and `items`. To save a count query, the implementation infers `count` from the current slice on partial or empty pages and only issues a `count()` when the page is full.

### Sharding {#sharding}

When tables span shards, the query routes reads, validates join safety, and merges ordered or grouped rows in memory. Some join or aggregate forms still fail when they are not shard-safe — see [FAQ](faq.md#multi-shard).

The query layer emits:

- `Switon\Query\Event\ShardValidationFailed` — when join shard validation fails, before the exception is rethrown
- `Switon\Query\Event\QuerySkipped` — when execution is skipped because conditions are unsatisfiable

---

## Keep in Mind {#keep-in-mind}

- `where(array)` covers the common grammar; switch to `whereRaw()` or fluent helpers (`whereBetween()`, `whereIn()`, `whereContains()`, `whereNull()`, etc.) when the form is clearer.
- `get()` throws `Switon\Query\Exception\NotFoundException` when no row matches; use `first()` to allow `null`.
- `paginate()` returns page metadata plus items, not just a slice of rows.
- Sharded reads may re-sort or merge rows in memory; rejected join forms surface as `ShardingNotSupportedException`.

---

## Next {#next}

- **To read common usage questions:** [FAQ](faq.md#query-faq)
- **To understand the design trade-offs:** [Design Rationale](design.md#design-rationale)
- **To run queries through repositories:** [ORM Package](https://github.com/switon-php/orm/blob/main/docs/README.md)
- **To use database clients and transactions:** [DB Package](https://github.com/switon-php/db/blob/main/docs/README.md)
- **To compare shard rules with the lower-level package:** [Sharding Package](https://github.com/switon-php/sharding/blob/main/docs/README.md)
