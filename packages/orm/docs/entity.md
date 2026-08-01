# Entity Guide {#entity-guide}

Typed entity classes are the ORM metadata surface: attributes describe tables, keys, columns, and validation. They do
not auto-save.

> **Prerequisites:** [ORM Package](README.md)

**Key features:**

- **Attribute-driven metadata** — table, connection, key, columns, and validation in one place
- **Type-first fields** — typed public properties for predictable hydration and serialization
- **Flexible key strategy** — database auto-increment or custom ID strategies via `#[Switon\Orm\Attribute\Id]`
- **Repository binding control** — convention by default, `#[Switon\Orm\Attribute\Repository]` for non-standard layouts
- **Action-parameter payload binding** — entity parameters can bind from request input through repository `fill()`
- **Lifecycle hooks** — `onEvent()` for create/update/delete hooks on entities
- **Validation attributes** — such as `#[Unique]`, `#[Exists]`, and `#[Immutable]`

## Quick Start {#quick-start}

Extend `Switon\Orm\Entity`, add metadata attributes, then continue in the [ORM Package](README.md#quick-start).

Minimal entity:

```php
<?php

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

    public string $created_at;
}
```

The same entity can be used directly in action parameters for write payloads:

```php
<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Controller;
use Switon\Routing\Attribute\PostMapping;

class UserController extends Controller
{
    #[Autowired] protected UserRepository $userRepository;

    #[PostMapping]
    public function createAction(User $user): User
    {
        return $this->userRepository->create($user);
    }
}
```

Binding uses the repository `fill()` path, so fillable rules and type hydration still apply.
For normal request writes, pass the entity directly or use an array when that is more convenient.

See
also: [Sharding Guide](sharding.md#sharding-guide) · [Validation attributes](#validation) · [ORM Codegen](https://github.com/switon-php/orm-codegen/blob/main/docs/README.md)

---

## Entity Attributes {#entity}

Entity attributes declare how entity classes and fields behave in storage and writes.

### `#[Table]` {#table}

Use `Switon\Orm\Attribute\Table` to define the table name. This is the entity's storage target.

```php
use Switon\Orm\Attribute\Table;
use Switon\Orm\Entity;

#[Table('users')]
class User extends Entity
{
}
```

If omitted, the table is inferred from the class name by naming strategy.

For sharding expressions on this attribute, see [Sharding Guide](sharding.md#sharding-guide).

### `#[Connection]` {#connection}

Use `Switon\Orm\Attribute\Connection` to define the DB connection. Use it when the entity should read and write through
a non-default client.

```php
use Switon\Orm\Attribute\Connection;
use Switon\Orm\Entity;

#[Connection('default')]
class User extends Entity
{
}
```

If omitted, the connection defaults to `default`.

For sharding expressions on this attribute, see [Sharding Guide](sharding.md#sharding-guide).

### `#[Id]` and ID strategies {#id-strategies}

Use `Switon\Orm\Attribute\Id` to mark primary key and optional generation strategy. It controls how ORM treats the
entity's identity.

```php
use Switon\Orm\Attribute\Id;
use Switon\Orm\Entity;

class User extends Entity
{
    #[Id]
    public ?int $id = null; // default: auto

    #[Id(strategy: 'uuid-v7')]
    public string $order_id;
}
```

Common strategy names include `auto`, `snowflake`, `uuid`, `uuid-v7`, and others.
Use `switon.yml` to override aliases when your project needs custom ID policies.

### `#[Column]` {#column}

Use `Switon\Orm\Attribute\Column` when a property name must map to a different database column name. Use it only for
renames, not for input control.

```php
use Switon\Orm\Attribute\Column;
use Switon\Orm\Entity;

class User extends Entity
{
    #[Column('user_name')]
    public string $name;
}
```

### `#[Fillable]` {#fillable}

Use `Switon\Orm\Attribute\Fillable` when a property should be included or excluded from mass assignment. It controls
what repository `fill()` accepts from input.

Only `#[Id]` and `#[Fillable]` mark properties as fillable by default.

```php
use Switon\Orm\Attribute\Fillable;
use Switon\Orm\Entity;

class Account extends Entity
{
    #[Fillable(false)]
    public int $balance;
}
```

- `true`: the property can be filled from input
- `false`: the property is excluded from fillable flow

### `#[Transient]` {#transient}

Use `Switon\Orm\Attribute\Transient` for runtime-only properties. It keeps computed or display-only fields out of
persistence metadata.

```php
use Switon\Orm\Attribute\Transient;
use Switon\Orm\Entity;

class User extends Entity
{
    #[Transient]
    public ?string $display_name = null;
}
```

Transient properties are excluded from persistence metadata.

### `#[ReferencedKey]` {#referenced-key}

Use `Switon\Orm\Attribute\ReferencedKey` when foreign-key naming must differ from inferred defaults. It changes the key
name other entities infer for this entity.

```php
use Switon\Orm\Attribute\ReferencedKey;

#[ReferencedKey('owner_ref')]
class User extends Entity
{
    #[Id]
    public int $user_id;
}
```

### `#[Repository]` {#repository}

Use `Switon\Orm\Attribute\Repository` when repository naming convention is not enough. It binds the entity to one
explicit repository class.

```php
use App\Repository\CustomUserRepository;
use Switon\Orm\Attribute\Repository;
use Switon\Orm\Entity;

#[Repository(CustomUserRepository::class)]
class User extends Entity
{
}
```

### `#[NamingStrategy]` {#naming-strategy}

Use `Switon\Orm\Attribute\NamingStrategy` to control class/property naming conversion. It only changes inference;
explicit `Table` and `Column` mappings still win.

```php
use Switon\Orm\Attribute\NamingStrategy;
use Switon\Orm\Entity;

#[NamingStrategy(NamingStrategy::CAMEL)]
class User extends Entity
{
}
```

### `#[DateFormat]` {#date-format}

Use `Switon\Orm\Attribute\DateFormat` when a date field should hydrate and store in a specific format, such as `U` or
`Y-m-d H:i:s`. It keeps date storage and hydration shape stable.

```php
use Switon\Orm\Attribute\DateFormat;
use Switon\Orm\Entity;

#[DateFormat('Y-m-d H:i:s')]
class User extends Entity
{
}
```

---

## Validation Attributes {#validation}

These are the entity-level validation attributes in ORM. The [Validation Package](https://github.com/switon-php/validation/blob/main/docs/README.md) provides
additional rules and extensions.

### `#[Unique]` {#unique}

`Switon\Orm\Attribute\Unique` validates field uniqueness, optionally with scoped filters. Use it when one value must not
repeat in storage.

```php
use Switon\Orm\Attribute\Unique;
use Switon\Orm\Entity;

class User extends Entity
{
    #[Unique]
    public string $email;

    #[Unique(filters: ['tenant_id'])]
    public string $username;
}
```

### `#[Exists]` {#exists}

`Switon\Orm\Attribute\Exists` validates that a value exists as a primary key in one explicit target entity. Use it for
optional foreign keys that still need existence checks when present.

```php
namespace App\Entity;

use Switon\Orm\Attribute\Exists;
use Switon\Orm\Entity;

class Post extends Entity
{
    #[Exists(User::class)]
    public ?int $user_id = null;
}
```

### `#[Immutable]` {#immutable}

`Switon\Orm\Attribute\Immutable` rejects updates that change an immutable field after creation. Use it for values that
must stay fixed after insert.

```php
use Switon\Orm\Attribute\Immutable;
use Switon\Orm\Entity;

class Order extends Entity
{
    #[Immutable]
    public string $order_no;
}
```

---

## Keep in Mind {#keep-in-mind}

- Use public typed properties for persistent fields.
- Use `#[Column]` for column renames only; use `#[Fillable]` for mass-assignment control.
- `fill()` sits behind normal repository writes.
- If layout does not match conventions, use `#[Repository(...)]` explicitly.
- Pick one concrete representation for time and number fields, and keep it consistent across the entity, schema, and
  serializers.

---

## Next {#next}

- [ORM Package](README.md) — first path, repository API, filters, and boundaries
- [Sharding Guide](sharding.md#sharding-guide) — shard expressions on `#[Connection]` / `#[Table]`
- [Validation attributes](entity.md#validation) — validation rules on entities
- [Sharding package](https://github.com/switon-php/sharding/blob/main/docs/README.md) — rule grammar, manager API, join validation
- [Query package](https://github.com/switon-php/query/blob/main/docs/README.md) — repository filter grammar
- [DB package](https://github.com/switon-php/db/blob/main/docs/README.md) — clients and transaction pinning
- [ID package](https://github.com/switon-php/id/blob/main/docs/README.md) — generator behavior and requirements
- [FAQ](faq.md#orm-faq) — common questions and troubleshooting
- [Design Rationale](design.md#design-rationale) — ORM architecture and trade-offs
