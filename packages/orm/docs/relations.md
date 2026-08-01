# Relation Guide {#relation-guide}

`switon/orm` lets entity classes declare relation metadata while repository calls decide what relation graph to load.

> **Prerequisites:** [ORM Package](README.md)

**Key features:**

- **Entity-owned relation metadata:** declare relation shape on the entity class.
- **Repository-owned loading:** request the graph you need in the repository call.
- **Stable relation families:** one-to-one, one-to-many, through, many-to-many, junction, and polymorphic links.

## Supported Shapes {#supported-shapes}

ORM supports these relation attributes:

- `BelongsTo`
- `HasOne`
- `HasMany`
- `HasManyThrough`
- `HasManyToMany`
- `BelongsToMany`
- `JunctionMany`
- `MorphTo`
- `MorphMany`

`BelongsToMany` is a semantic alias of `HasManyToMany`.

---

## Loading {#loading}

Relations are loaded in repository calls through the `fields` argument.

```php
$users = $this->users->all([], [
    'id',
    'name',
    'posts' => ['id', 'title'],
]);
```

Payload forms:

- `[]` uses the relation's default field set
- `array` selects fields and nested child relations
- `callable` customizes the relation query

If you do not request a relation, property access will not load it later.

---

## Common Shapes {#common-shapes}

### One-To-One {#one-to-one}

`BelongsTo` is the many-to-one side. `HasOne` is the inverse one-to-one side.

```php
use Switon\Orm\Attribute\BelongsTo;
use Switon\Orm\Attribute\HasOne;

class User extends Entity
{
    #[HasOne]
    public ?Profile $profile = null;
}

class Profile extends Entity
{
    public int $user_id;

    #[BelongsTo]
    public ?User $user = null;
}
```

### One-To-Many {#one-to-many}

`HasMany` declares a one-to-many link.

```php
use Switon\Orm\Attribute\HasMany;

class User extends Entity
{
    #[HasMany(Post::class, orderBy: ['created_at' => SORT_DESC])]
    public array $posts = [];
}
```

### Many-To-Many {#many-to-many}

`HasManyToMany` and `BelongsToMany` model many-to-many links through a junction entity.

```php
use Switon\Orm\Attribute\BelongsTo;
use Switon\Orm\Attribute\HasManyToMany;

class UserRole extends Entity
{
    public int $user_id;
    public int $role_id;

    #[BelongsTo]
    public ?User $user = null;

    #[BelongsTo]
    public ?Role $role = null;
}

class User extends Entity
{
    #[HasManyToMany(UserRole::class)]
    public array $roles = [];
}
```

### Through And Junction {#through-and-junction}

`HasManyThrough` loads through an intermediate entity. `JunctionMany` is for junction-entity access when the junction
entity itself is the relation model.

```php
use Switon\Orm\Attribute\HasManyThrough;

class User extends Entity
{
    #[HasManyThrough(Comment::class, Post::class)]
    public array $comments = [];
}
```

### Polymorphic {#polymorphic}

`MorphTo` and `MorphMany` model polymorphic links based on table and id fields.

```php
use Switon\Orm\Attribute\MorphTo;

class Comment extends Entity
{
    public string $commentable_table;
    public int $commentable_id;

    #[MorphTo(tableField: 'commentable_table', idField: 'commentable_id')]
    public mixed $commentable;
}
```

---

## Keep in Mind {#keep-in-mind}

- Relation metadata stays on entity classes.
- Loading shape stays in the repository call.
- `BelongsToMany` behaves like `HasManyToMany`.
- `MorphTo` depends on stable table-name values.

## Next {#next}

- **To see entity metadata around these relations:** [Entity Guide](entity.md#entity)
- **To use relations from repository calls:** [ORM Package](README.md)
- **To understand why relation loading stays explicit:** [Design Rationale](design.md#design-rationale)
