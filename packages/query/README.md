# Switon Query Package

[![Query CI](https://img.shields.io/github/actions/workflow/status/switon-php/query/ci.yml?branch=main&label=Query%20CI)](https://github.com/switon-php/query/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's fresh-query and shard-aware SQL layer for fluent filters, pagination, and merged reads.

## Highlights

- **Fresh query objects:** `QueryBuilderInterface::from()` starts a fresh query each time.
- **Fluent read building:** `QueryInterface` chains filters, joins, ordering, grouping, limits, and pagination.
- **Shard-aware execution:** `Query` can resolve shards and merge multi-shard results.
- **Explicit table context:** `Table` carries table name and connection together when routing matters.
- **Paging support:** `Paginator` keeps page boundaries explicit.

## Installation

```bash
composer require switon/query
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Query\QueryBuilderInterface;
use Switon\Query\PaginatorInterface;

final class UserService
{
    #[Autowired] protected QueryBuilderInterface $queryBuilder;

    public function listAction(int $page = 1): PaginatorInterface
    {
        return $this->queryBuilder->from('users')
            ->where(['status' => 1])
            ->orderBy(['created_at' => SORT_DESC])
            ->paginate($page, 10);
    }
}
```

Docs: https://docs.switon.dev/latest/query

## License

MIT.
