# Sharding Package {#sharding-package}

`switon/sharding` maps reads and writes to shard connections and tables from compact rules.

**Key features:**

- **Rule-driven shard selection:** derive shard names from context values instead of hard-coding branches
- **Small rule syntax:** use shorthand forms like `db:user_id%4` and `user:user_id%8`
- **Explicit shard selection:** `unique()`, `multiple()`, and `all()` cover the common routing modes
- **Built-in strategies:** `modulo`, `list`, `range`, `crc32`, and `hash`
- **Fail-fast validation:** invalid rules, multi-shard single-target requests, and unsafe joins fail early

---

## Installation {#installation}

**Requirements:** PHP 8.3+ plus the framework pieces that provide core utilities and events (`switon/core`, `switon/event`).

```bash
composer require switon/sharding
```

---

## Quick Start {#quick-start}

```php
use Switon\Core\Attribute\Autowired;
use Switon\Sharding\ShardingManagerInterface;

class UserRepository
{
    #[Autowired] protected ShardingManagerInterface $shardingManager;

    public function find(int $userId): array
    {
        return $this->shardingManager->unique(
            'db:user_id%4',
            'user:user_id%8',
            ['user_id' => $userId],
        );
    }
}
```

---

## Core Concepts {#core-concepts}

### Routing Modes {#routing-modes}

- `unique()` returns one connection and one table.
- `multiple()` returns all matched shards.
- `all()` ignores context and returns every configured shard.

### Rules {#rules}

- Supported forms are only `base`, `base:field%N`, `base:0,1,2`, and `base:field:strategy:config`.
- `base:field%N` requires a field name that matches a PHP identifier.
- `base:field:strategy:config` is the full form; other colon counts are invalid.
- Shard names are formatted as `base_suffix`; modulo/hash-like rules with divisor `1` return `base` without a suffix.

### Strategies {#strategies}

- `modulo` fits numeric shard keys.
- `crc32` and `hash` fit string shard keys.
- `hash` accepts `N` or `algo,N`, and a leading `0` in the divisor string enables zero-padded suffixes.
- `range` fits inclusive numeric intervals.
- `list` fits predefined shard sets and formats each item as `base_item`.

### Shard Context {#context}

- Arrays and objects can be used as shard context.
- Missing context can fan out to all shards.
- Objects are read with `get_object_vars()`.
- Numeric array contexts are treated as multiple contexts for `unique()`.
- `unique()` rejects context that resolves to more than one shard.

### Boundaries {#boundaries}

- Cross-shard joins are not supported.
- Distributed transactions are out of scope.
- Sharding resolves names; it does not create databases or tables.
- Unsafe sharded joins are validated in the query layer — see [Query package](https://github.com/switon-php/query/blob/main/docs/README.md#sharding).
- Some query patterns raise `Switon\Sharding\Exception\ShardingNotSupportedException`.

---

## Configuration {#configuration}

Most apps only need the manager and its strategy set.

```yaml
Switon\Sharding\ShardingManagerInterface:
  strategies:
    modulo: Switon\Sharding\Strategy\ModuloStrategy
    list: Switon\Sharding\Strategy\ListStrategy
    range: Switon\Sharding\Strategy\RangeStrategy
    crc32: Switon\Sharding\Strategy\Crc32Strategy
    hash: Switon\Sharding\Strategy\HashStrategy
```

You can replace or extend `strategies` with your own `StrategyInterface` implementations.

### Option Discovery {#option-discovery}

Use the current options directly:

```bash
bash bin/console config:options 'Switon\Sharding\ShardingManagerInterface'
```

---

## Keep in Mind {#keep-in-mind}

- Missing shard keys can fan out.
- `list` ignores the context value.
- `multiple()` returns `[]` when the table side resolves to no shard.
- `range` values outside all intervals resolve to no shard.
- Changing shard counts remaps rows for hash-like strategies.
- `unique()` with an empty context array fails fast.

---

## Next {#next}

- **Need setup questions answered quickly:** [FAQ](faq.md#sharding-faq)
- **Need design trade-offs:** [Design Rationale](design.md#design-rationale)
- **Need ORM entity mapping:** [ORM sharding guide](https://github.com/switon-php/orm/blob/main/docs/sharding.md#sharding-guide)
