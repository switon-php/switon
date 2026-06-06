# Reverse consumer index (Typical consumer)

How to add **sparse reverse `@see` edges** from a dependency package to its most typical upstream consumers.

## Why this matters

When AI is reading a dependency package (for example `switon/query` or `switon/db`), the fastest next hop is often “who uses this in a real workflow”. A small number of reverse edges makes that hop **one step**.

## Rule of thumb

- Add **1–3** `Typical consumer` edges per dependency entrypoint.
- Prefer **stable consumers** (interfaces, main workflow classes), not tests or rare helpers.
- Do not add reverse edges for “hub” packages with many consumers. Keep reverse edges **sparse and high-signal**.

## Where to place the reverse edges

Pick **one** entrypoint per package, typically:

- `*Interface` that represents the package API boundary, or
- a mechanism entry class (dispatcher/manager/provider) if the interface is too generic.

Example patterns:

- `Switon\Query\QueryInterface` → `Switon\Orm\EntityManagerInterface`, `Switon\Orm\RepositoryInterface`
- `Switon\Db\ClientInterface` → `Switon\Query\Query`, `Switon\Orm\EntityManager`
- `Switon\Crypto\CipherInterface` → `Switon\Jwt\Jwt`

## How to find candidates (script)

Use incoming edges to find packages with few consumers:

```bash
php scripts/use-deps-tree.php --reverse --max-in=3
```

To inspect one package:

```bash
php scripts/use-deps-tree.php --reverse --package=query
```

## How to write the `@see` lines

Add to **class-level PHPDoc**:

- `@see \Switon\Upstream\SomeEntrypoint Typical consumer`

Notes:

- Keep it short and stable.
- Prefer class/interface over method-level unless the consumer entry is truly a single method workflow.

