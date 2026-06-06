# Persist data {#persist-data}

Use this guide when you want to replace in-memory state with durable storage and choose the right Switon data layer before read and write concerns spread across your application.

## Choose the right layer {#layer}

| Layer | Best when | Keep in mind |
|---|---|---|
| [ORM](../orm/README.md) | your main application boundary is entities, repositories, and explicit CRUD | best default for stored business data; no hidden flush and no lazy-loaded relation reads |
| [DB](../db/README.md) | one fixed SQL statement or one explicit transaction is clearer than a repository abstraction | the injected client is stateless; use `getTransient('default')` when one operation needs a pinned transient handle for a transaction |
| [Query](../query/README.md) | dynamic filters, list/search screens, or admin-style query composition are the hard part | best for assembling read-side filtering, sorting, paging, and search rules; not a full replacement for repository-led writes |

Common shape: **ORM** for normal writes, **Query** for dynamic reads, **DB** for fixed SQL or explicit transactions.

## Recommended build order {#build-order}

1. Configure one DB client first.
2. Choose the boundary you want to live on: ORM, DB, or Query.
3. Persist one small path before you generalize.
4. Add Query only when read composition becomes the real problem.
5. Drop to DB only for fixed SQL or explicit transactions.
6. Stop before sharding, codegen, or caching.

## Minimal wiring {#minimal-wiring}

Use short code only to show the seams between the data layers.

Application-level names like `App\Entity\User`, `UserRepository`, and `UserService` below are illustrative. The Switon framework symbols and API shapes are the stable part. For a runnable baseline, compare `tests/doc-proofs/tutorial-mainline-app/`.

### One DB client base {#wiring-client}

All three layers start from the same DB client definition:

```yaml
# switon.yml (project root)
Switon\Db\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: ${DATABASE_URL}
```

That is enough for the first stored path. Add more connections or topology only when the application really needs them.
In the runnable proof app, the same SQLite setup is provided in `config/client.php` as `sqlite:` plus an absolute file path.
If you use SQLite for the first pass, pick one real file path and keep using that same file for both schema creation and app runtime.

### Repository-first ORM path {#wiring-orm}

When stored business data should live behind repositories, start here.
Assume the table or schema already exists before you rely on the entity mapping below; if schema setup is still the missing step, continue with [ORM](../orm/README.md) and [Entity Guide](../orm/entity.md#entity) first.
This guide does not introduce one framework-owned migration tool. The practical beginner rule is simpler:

- choose the database through `DATABASE_URL`, for example `mysql://...`, `pgsql://...`, or `sqlite:...`
- create the table with your project's existing migration path or one explicit SQL script before you run the repository code
- if you are still proving the shape locally, SQLite is a fine first database for the first stored path

When repository writes look correct but later reads stay empty, check `DATABASE_URL` first. The most common first-pass mistake is writing schema or seed data into one database and running the app against another.

```php
namespace App\Entity;

use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Entity;

#[Table('users')]
class User extends Entity
{
    #[Id]
    public ?int $id = null;

    public string $name;

    public string $email;
}
```

```php
namespace App\Repository;

use App\Entity\User;
use Switon\Orm\Repository;

/** @extends Repository<User> */
class UserRepository extends Repository
{
    protected string $entityClass = User::class;
}
```

```php
use Switon\Core\Attribute\Autowired;

class UserService
{
    #[Autowired] protected UserRepository $users;

    public function register(string $name, string $email): User
    {
        $user = new User();
        $user->name = $name;
        $user->email = $email;

        return $this->users->create($user);
    }
}
```

If your repository and entity already follow the default `App\Repository\UserRepository` -> `App\Entity\User` naming, Switon can infer the entity class automatically and this explicit `$entityClass` line can stay out of the first pass.
Use `#[Column]` only when the database column name differs from the property name.

### Dynamic read path with Query {#wiring-query}

Add [Query](../query/README.md) when list and search conditions become the noisy part:

```php
use Switon\Core\Attribute\Autowired;
use Switon\Query\QueryBuilderInterface;

class UserService
{
    #[Autowired] protected QueryBuilderInterface $queries;

    public function listActiveUsers(string $keyword = ''): array
    {
        return $this->queries->from('users')
            ->where([
                'status' => 'active',
                'name*=' => $keyword,
            ])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(20)
            ->all();
    }
}
```

Here, `'name*=' => $keyword` uses the Query filter grammar: `*=` means "contains", so Query compiles it like `LIKE '%keyword%'`. That operator comes from [Query](../query/README.md), not from PHP itself.

### Explicit transaction path with DB {#wiring-db}

Drop to [DB](../db/README.md) when you need one pinned connection and one obvious transaction boundary.
Assume `$this->db` below is an autowired `Switon\Db\ClientInterface`.

```php
$tx = $this->db->getTransient('default');

try {
    $tx->begin();
    $orderId = $tx->insert('orders', ['user_id' => 1, 'total' => 100], true);
    $tx->insert('order_items', ['order_id' => $orderId, 'product_id' => 42, 'quantity' => 2]);
    $tx->commit();
} catch (\Throwable $e) {
    $tx->rollback();
    throw $e;
}
```

Keep the transient handle in a local variable. The shared DI-managed client is the normal stateless path.

## What to avoid first {#pitfalls}

- **Designing every table and relation up front** — prove one stored path before you generalize the model
- **Using `Query` before read composition is actually hard** — fixed reads should stay fixed
- **Dropping to raw SQL for normal repository work** — use [ORM](../orm/README.md) when entities and repositories already express the job well
- **Assuming repository inference always works across custom naming** — if the repository and entity no longer follow the default naming pair, set `protected string $entityClass = User::class;` explicitly
- **Keeping transient DB handles on long-lived services** — pinned connections belong to short local transaction scope
- **Adding sharding, code generation, or cache layers first** — [Sharding](../sharding/README.md), [ORM Codegen](../orm-codegen/README.md), and [Cache](../cache/README.md) come after the first stored path is stable

## Go deeper {#go-deeper}

- **To connect this after your first API or page path:** [Build an API](build-an-api.md#build-an-api) · [Build HTML pages](build-html-pages.md#build-html-pages)
- **To place request-wide hooks, action wrappers, or HTTP failure policy once the request path is real:** [Add cross-cutting HTTP behavior](add-cross-cutting-http-behavior.md#add-cross-cutting-http-behavior)
- **To protect the first stored path before auth, cache, or job growth:** [Add testing to a working app](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
- **To add file metadata plus blob storage without exposing filesystem paths:** [Handle file uploads and downloads](handle-file-uploads-and-downloads.md#handle-file-uploads-and-downloads)
- **To learn the same move chapter by chapter:** [04 — Stored data](../tutorial/04-data.md#04-data)
- **To reuse the stored service from the console next:** [Add CLI](add-cli.md#add-cli) · [05 — CLI reuse](../tutorial/05-cli.md#05-cli) · [CLI](../cli/README.md)
- **To add login state, bearer tokens, or protected endpoints after storage is stable:** [Add authentication](add-authentication.md#add-authentication) · [Session](../session/README.md) · [JWT](../jwt/README.md) · [Authz](../authz/README.md)
- **To add cached read paths after storage is stable:** [Add caching](add-caching.md#add-caching) · [Faster list](../tutorial/faster-list.md#faster-list) · [Cache](../cache/README.md)
- **To stay on repository-led entities and CRUD:** [ORM](../orm/README.md) · [Entity Guide](../orm/entity.md#entity)
- **To use fixed SQL or explicit transactions:** [DB](../db/README.md)
- **To compose dynamic list and search queries:** [Query](../query/README.md)
- **To grow into schema-driven helpers or shard-aware data work later:** [ORM Codegen](../orm-codegen/README.md) · [Sharding](../sharding/README.md)
