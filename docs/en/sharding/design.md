# Design Rationale {#design-rationale}

`switon/sharding` keeps shard routing explicit and fail-fast instead of pretending distributed SQL is transparent.

## See also {#see-also}

- [Sharding Package](README.md) — routing rules, configuration, and limits
- [FAQ](faq.md#sharding-faq) — short answers for setup and common issues
- [ORM sharding guide](../orm/sharding.md#sharding-guide) — entity metadata and repository behavior

---

## Core Problem {#core-problem}

Sharding assumptions hide easily in query code, helpers, and deployment conventions.

## Primary Goal {#primary-goal}

`switon/sharding` resolves shard targets and validates boundaries.

- shard intent stays visible
- single-shard operations fail fast when ambiguous
- fan-out stays explicit

## Trade-Off {#trade-off}

- **Benefit:** route decisions are easier to review.
- **Benefit:** unsafe joins fail early.
- **Cost:** callers must choose `unique()`, `multiple()`, or `all()`.
- **Cost:** distributed SQL is not treated as one transparent database.

## Deliberate Limits {#deliberate-limits}

- no distributed transactions
- no cross-shard joins
- no database or table creation
- no automatic re-sharding plan
