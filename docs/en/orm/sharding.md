# Sharding Guide {#sharding-guide}

`switon/orm` keeps shard routing on entity metadata and resolves targets before repository writes run.

**Key features:**

- **Entity metadata routes shards** — `#[Connection]` and `#[Table]` carry the routing rules.
- **Write paths need one shard** — `create()`, `update()`, `delete()`, `put()`, and `createMany()` fail when the
  target is not unique.
- **Read paths can fan out** — repository reads may resolve one shard or many shards depending on context.
- **Batch writes stay homogeneous** — `createMany()` expects one entity type and one resolved shard.
- **Physical targets still matter** — ORM routes only; it does not create databases or tables.

---

## Quick Start {#quick-start}

Put shard expressions on the entity when one class must route to different physical targets.

```php
use Switon\Orm\Attribute\Connection;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Entity;

#[Connection('db:user_id%4')]
#[Table('orders:user_id%8')]
class Order extends Entity
{
    #[Id]
    public ?int $order_id = null;

    public int $user_id;
    public float $amount;
}
```

Use plain names such as `default` and `orders` when the entity is not sharded.

---

## Core Concepts {#core-concepts}

### Routing Inputs {#routing-inputs}

`Switon\Orm\Attribute\Connection` controls the database target. `Switon\Orm\Attribute\Table` controls the table target.
Both can stay plain names or use sharding expressions.

### Read And Write Paths {#write-paths}

`Switon\Orm\ShardingInterface` exposes four routing shapes:

- `getAnyShard()` for any available target
- `getUniqueShard()` for write paths that must resolve one target
- `getMultipleShards()` for reads that may fan out
- `getAllShards()` for full expansion

`getUniqueShard()` is the write boundary. It fails when a call would span multiple databases or tables.

### Batch Writes {#batch-writes}

`createMany()` accepts one entity type and one shard. If rows may map to different shards, group them first.

### Transactions {#transactions}

`#[Switon\Orm\Attribute\Transactional]` works best when the whole workflow stays on one shard. If a workflow may touch
multiple shards, split it by shard group.

---

## Configuration {#configuration}

Shard metadata still needs real database clients in `switon.yml`.

```yaml
# switon.yml
Switon\Db\ClientInterface:
  class: Switon\Di\Factory
  db_0:
    uri: '<dsn-for-db_0>'
  db_1:
    uri: '<dsn-for-db_1>'
```

---

## Keep in Mind {#keep-in-mind}

- Include shard-driving fields before write calls that must resolve one target.
- Split `createMany()` by shard key first when rows may map to different shards.
- ORM only routes to existing targets.
- Prefer shard-key-constrained reads in latency-sensitive paths.

## Next {#next}

- **To map entities and keys:** [Entity Guide](entity.md#entity)
- **To understand repository persistence:** [ORM Package](README.md)
- **To compare shard rules with the lower-level package:** [Sharding package](../sharding/README.md)
- **To check cross-shard query routing:** [Query package](../query/README.md)
- **To inspect common questions:** [FAQ](faq.md#orm-faq)
- **To review trade-offs:** [Design Rationale](design.md#design-rationale)
