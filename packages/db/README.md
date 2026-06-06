# Switon DB Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/db/ci.yml?branch=main&label=CI)](https://github.com/switon-php/db/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's database client layer for direct table operations, routed reads, pooled connections, transaction-safe
connections, and SQL observability.

## Highlights

- **Table operations:** `ClientInterface` offers `insert`, `update`, `upsert`, and `delete` alongside raw SQL.
- **Read/write routing:** reads and writes can be split across the right pool automatically.
- **Connection pooling:** read and write calls reuse pooled connections, with transient clients available for
  transactions.
- **Transient transaction clients:** local transactions can use a client pinned to one connection.
- **Metadata and SQL helpers:** table metadata and SQL builders are available from the same client.
- **Observable queries:** DB events can feed collectors such as `SqlCollector`.

## Installation

```bash
composer require switon/db
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Db\ClientInterface;

final class UserRepository
{
    #[Autowired] protected ClientInterface $db;

    public function find(int $id): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM [users] WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        return $rows[0] ?? null;
    }

    public function rename(int $id, string $name): void
    {
        $db = $this->db->getTransient('default');
        $db->begin();

        try {
            $db->executeUpdate(
                'UPDATE [users] SET name = :name WHERE id = :id',
                ['id' => $id, 'name' => $name],
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
```

Docs: https://docs.switon.dev/latest/db

## License

MIT.
