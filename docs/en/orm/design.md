# Design Rationale {#design-rationale}

`switon/orm` makes persistence reviewable by keeping reads, writes, relation loading, and shard routing explicit.

## See also {#see-also}

- [ORM Package](README.md) — usage, concepts, and first integration
- [FAQ](faq.md#orm-faq) — short answers and common pitfalls
- [Relation Guide](relations.md#relation-guide) — supported relation shapes and loading examples
- [DB Package](../db/README.md) — SQL client, transient pinning, and topology
- [Query Package](../query/README.md) — filters and composition without the entity layer
- [Sharding Package](../sharding/README.md) — shard rules referenced from entity metadata

---

## Why This Exists {#why-this-exists}

ORM exists to stop data access style from drifting across services.

When each module invents its own read form, write form, or relation-loading style, the application becomes harder to
review. Switon standardizes that boundary around repositories and explicit persistence.

## Main Trade-Off {#main-trade-off}

Switon chooses explicit persistence over hidden state.

- entity changes in memory do not flush later by accident
- relations load only when requested
- repositories stay the normal application boundary
- the same row may exist as multiple entity instances
- related entities persist through explicit calls, not cascade magic

That makes behavior easier to reason about, at the cost of fewer magic conveniences.

## Relation Loading {#relation-loading}

The package keeps relation loading visible in the repository call instead of property-triggered lazy loading. That keeps
query cost and loaded data easier to predict.

## State And Routing {#state-and-routing}

ORM keeps shard routing explicit rather than hiding it behind a unit-of-work style flush.

- reads may fan out when needed
- writes must resolve one target
- batch writes must already be shard-safe

That keeps cross-shard behavior practical without making write outcomes ambiguous.
