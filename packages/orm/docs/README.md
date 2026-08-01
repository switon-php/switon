# ORM Package {#orm-package}

`switon/orm` gives Switon apps typed entities, repository-first persistence, explicit relation loading, and shard-aware
routing.

**Key features:**

- **Repository-first boundary:** keep application code on repository subclasses such as `App\Repository\UserRepository`.
- **Typed entity metadata:** declare tables, keys, columns, relations, and validation on entity classes.
- **Explicit relation loading:** request relation graphs in repository calls.
- **Shard-aware routing:** writes and batch inserts resolve one target before they run.
- **Page binding:** `Switon\Orm\Page` binds request `page` and `size` values for paginated actions.

---

## Installation {#installation}

**Requirements:** PHP 8.3+, `switon/core`, `switon/db`, `switon/query`, `switon/sharding`, `switon/validation`,
`switon/event`, `switon/di`, `switon/binding`, `switon/id`, `switon/principal`

```bash
composer require switon/orm
```

---

## Quick Start {#quick-start}

Define one entity, one repository, and one service method.

```php
<?php

namespace App\Entity;

use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Fillable;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Entity;

#[Table('users')]
class User extends Entity
{
    #[Id]
    public ?int $id = null;

    #[Fillable]
    public string $name;

    #[Fillable]
    public string $email;
}
```

```php
<?php

namespace App\Repository;

use App\Entity\User;
use Switon\Orm\Repository;

/**
 * @extends Repository<User>
 */
class UserRepository extends Repository
{
}
```

```php
<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Switon\Core\Attribute\Autowired;

class UserService
{
    #[Autowired] protected UserRepository $userRepository;

    public function register(string $name, string $email): User
    {
        $user = new User();
        $user->name = $name;
        $user->email = $email;

        return $this->userRepository->create($user);
    }
}
```

This keeps the first integration narrow:

- entity metadata lives on the entity class
- repository methods own reads and writes
- in-memory changes do nothing until a repository write runs

---

## Core Concepts {#core-concepts}

ORM stays predictable when these boundaries stay clear.

### Entity Model {#entity-model}

`Switon\Orm\Entity` is the typed record model. It owns table, key, column, relation, and validation metadata.

### Repository Boundary {#repository-boundary}

`Switon\Orm\RepositoryInterface` is the application boundary for reads and writes.

Use repositories for `get()`, `find()`, `all()`, `paginate()`, `create()`, `update()`, `delete()`, `save()`, `put()`,
and other operations.

`create()` validates input, runs entity auto-fill hooks, and reloads database defaults after insert. `put()` skips that
normal write pipeline and is only for trusted complete data, but it still emits create events. `save()` routes to
`update()` when the primary key is set and to `create()` otherwise. `update()` loads the original row first so it can
compare and validate changes.

### Relation Loading {#relation-loading}

Entity classes declare relation metadata. Repository calls decide what gets loaded.
See [Relation Guide](relations.md#relation-guide).

### Page Binding {#page-binding}

`Switon\Orm\Page` models pagination input for action parameters and repository pagination.

```php
namespace App\Controller;

use App\Repository\UserRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Controller;
use Switon\Orm\Page;
use Switon\Query\Paginator;

class UserController extends Controller
{
    #[Autowired] protected UserRepository $users;

    public function indexAction(Page $page): Paginator
    {
        return $this->users->paginate($page, ['status' => 'active']);
    }
}
```

Defaults are `page = 1` and `size = 10`.
Values below `1` fail binding. The page resolver defaults to a `page * size` cap of `1000`; use
`#[Switon\Orm\Attribute\PageLimit]` when one action needs a per-action cap, and inspect the active options with
`bash bin/console config:options 'Switon\Orm\PageResolver'`.

### Sharding {#sharding}

Sharding is declared on entity metadata. Reads may fan out, but writes need one resolved target.

`createMany()` only works when the whole batch already points to one shard.
Bulk `updateAll()` and `deleteAll()` follow query-style execution and can fan out across shards.

If you do not shard yet, plain connection and table names are enough.

---

## Configuration {#configuration}

Start with the defaults. Add configuration only when you need DB wiring, pagination limits, or code-generation scans.

### Database Wiring {#database-wiring}

ORM resolves `Switon\Db\ClientInterface` through the container. The exact client options belong to the DB package.

```bash
bash bin/console config:options 'Switon\Db\ClientInterface'
```

### Code Generation {#code-generation}

`switon/orm-codegen` is optional and provides entity and repository scaffolding from database metadata.

---

## Keep in Mind {#keep-in-mind}

- `create()`, `update()`, and `delete()` do not happen automatically when you mutate an entity in memory.
- Request relation loading explicitly in the repository call.
- Use `put()` only for trusted complete data.
- `fill()` treats `null` as not provided, so it does not write SQL `NULL`.
- `create()` from an array clears the primary key before insert for safety.
- Keep shard-driving fields available before a write if the entity is sharded.

---

## Next {#next}

- **To map entities and relations in detail:** [Entity Guide](entity.md#entity)
- **To learn supported relation shapes:** [Relation Guide](relations.md#relation-guide)
- **To route shard behavior:** [Sharding Guide](sharding.md#sharding-guide)
- **For ad-hoc SQL composition without entities:** [Query package](../../query/docs/README.md)
- **For raw SQL, transactions, and client wiring:** [DB package](../../db/docs/README.md)
- **To check common ORM questions:** [FAQ](faq.md#orm-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
