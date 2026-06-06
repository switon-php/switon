# DB Package {#db-package}

`switon/db` gives Switon apps direct SQL with named binds, a stateless default client, pinned transient connections for
local transactions, and optional read/write routing.

**Key features:**

- **Direct SQL contract:** inject `Switon\Db\ClientInterface` and use named placeholders.
- **Stateless by default:** ordinary reads and writes borrow a connection per call.
- **Pinned local scopes:** `getTransient('default')` keeps one connection for a transaction or read-after-write check.
- **Read/write routing:** reads can use replicas while writes stay on the master path.
- **Portable SQL and observability:** `[identifier]` quoting and lifecycle events keep SQL portable and inspectable.

---

## Installation {#installation}

**Requirements:** PHP 8.3+, `ext-pdo`, and a PDO driver for your database.

```bash
composer require switon/db
```

---

## Quick Start {#quick-start}

- Configure one database.
- Inject the client.
- Use parameterized SQL.

```yaml
# switon.yml
Switon\Db\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: mysql://root:password@localhost/myapp
```

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Db\ClientInterface;

final class UserService
{
    #[Autowired] protected ClientInterface $db;

    public function findById(int $id): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [name] FROM [users] WHERE [id] = :id',
            ['id' => $id],
        );

        return $rows[0] ?? null;
    }
}
```

- If one local scope must keep the same connection, pin a transient client:

```php
$tx = $this->db->getTransient('default');
$tx->begin();

try {
    $tx->insert('users', ['name' => 'Jane']);
    $tx->commit();
} catch (\Throwable $e) {
    $tx->rollback();
    throw $e;
}
```

- Use `getTransient('default')` for transactions and read-after-write checks.
- `auto` and `slave` are read-routing hints, not transaction scopes.
- For most apps, one `default.uri` and one injected client are enough.

---

## Core Concepts {#core-concepts}

### Transient Connections {#transient-connections}

The shared `ClientInterface` is the ordinary path.

- Use `getTransient('default')` when one local scope needs transaction state or read-after-write consistency.
- Use `getTransient('auto')` only when you want the read side to pick `slave` when a replica exists.
- Use `getTransient('slave')` only when you want to force a replica read and can tolerate fallback to `default` when no
  replica exists.

For ORM-led scopes, `Switon\Db\TransactionManagerInterface` returns the already-bound client instead of starting a
separate scope.

### Read/Write Paths {#read-write-paths}

- `fetchAll()` may read from a replica when the topology has one.
- `insert()`, `update()`, `upsert()`, and `delete()` use the write path.
- `executeInsert()` / `executeUpdate()` / `executeDelete()` and `insertBySql()` / `updateBySql()` / `deleteBySql()` are
  the direct SQL entrypoints.
- `insert(..., true)` returns the inserted ID instead of the affected-row count.
- `[identifier]` notation keeps manual SQL portable across supported drivers.

### Connection Topology {#connection-topology}

- Start with one `default.uri`.
- Add more only when different services need different databases, or when one `uri` should become master plus replicas.

A multi-URI `default.uri` treats the first URI as master and the rest as replicas.

```yaml
# switon.yml
Switon\Db\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: mysql://root:password@master/app,mysql://root:password@replica1/app,mysql://root:password@replica2/app
```

- Use this shape when reads may go to replicas and writes stay on the first URI.

---

## Configuration {#configuration}

Use URI strings in this shape:

```text
driver://username:password@host:port/database?options
```

Common examples:

```text
mysql://root:password@localhost/myapp
pgsql://postgres:password@localhost/myapp
sqlite:///path/to/database.db
sqlite::memory:
```

The package also supports `mssql://...`.

Stable query options live inside `uri`:

- `pool_size` controls pool sizing; `4/8` splits master and replica pools
- `timeout` controls pool acquisition timeout
- `prefix` applies table prefixes to high-level CRUD helpers
- `charset`, `readonly`, and `emulate_prepares` are adapter-specific options
- replica URIs get `readonly=1` automatically when the pool parser builds slave connections

---

## Option Discovery {#option-discovery}

Use the current option surface directly:

```bash
bash bin/console config:options 'Switon\Db\ClientInterface'
```

---

## Keep in Mind {#keep-in-mind}

- `fetchAll()` may hit a replica; pin a transient client when immediate consistency matters.
- `update()` and `delete()` reject empty conditions and single-word conditions to avoid accidental full-table writes.
- Use `WHERE FALSE` when you need a deliberate no-op delete.
- `allocateIds()` must run outside transactions.
- `config:options` is the fastest way to confirm the current binding and option surface.
- `begin()` / `commit()` / `rollback()` require a transient client.

---

## Next {#next}

- **Common questions:** [FAQ](faq.md#db-faq)
- **Why the package is shaped this way:** [Design Rationale](design.md#design-rationale)
- **Testing patterns:** [Testing](testing.md#testing-database-code)
- **Cross-driver internals (quoting, transaction errors):** [DB internals](internals/README.md)
