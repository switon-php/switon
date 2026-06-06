# Grouping

Use this reference for **how to scout scope and group packages** in broad runs.

## When to read this file

- Skip this file for single-file work and ordinary small local edits.
- Read it only when the scope spans multiple related packages and execution order or terminology continuity would otherwise get unstable.
- This file supports single-agent broad runs; it is not a default prerequisite.

## Batch strategy

- Prefer **conceptual adjacency and shared terminology** over mechanical alphabetical order.
- Typical clusters in this repo:
  - HTTP family: `http`, `http-client`, `http-cache`, `router`, `session`, `throttle`
  - Data family: `db`, `orm`, `orm-codegen`, `query`, `sharding`, `redis`
  - Runtime/Core family: `core`, `context`, `di`, `invoker`, `executor`, `event`, `kernel`
  - Async/Integration family: `queue`, `schedule`, `sync`, `pool`, `rpc`, `mail`, `storage`
- Use alphabetical order only as a fallback tiebreaker.

## Baseline scouting

- Scan package `README.md` files only.
- Do **not** use `faq.md` or `design.md` for baseline selection.
- Use scouting to pick anchors, estimate overlap/risk, and build related-package batches.
- If the scope shares terminology across packages, hand off terminology planning to `references/terms.md` before broad execution.
