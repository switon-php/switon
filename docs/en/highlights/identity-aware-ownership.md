# Identity-Aware Ownership Safety {#identity-aware-ownership}

Switon uses one identity-aware ownership model across list queries and single-entity actions, so the same action-level rule can constrain both "show me my records" and "load only my record".

**Why this is a highlight:**
- `Switon\Http\Attribute\IdentityFilter` keeps ownership scope server-owned at the HTTP boundary
- the same rule shape applies to `Switon\Http\Criteria`; entity binding uses entity `#[Switon\Orm\Attribute\Owner]` metadata
- single-record ownership leaks are quieter than list leaks, so they benefit more from framework-default checks
- `Switon\Principal\IdentityInterface` gives multiple layers one stable source of current-identity data

## What this article highlights {#highlights}

- `Switon\Http\Attribute\IdentityFilter` adds server-owned ownership scope at the HTTP boundary.
- `IdentityFilter` applies to `Switon\Http\Criteria`; ORM entity binding uses `#[Switon\Orm\Attribute\Owner]` on the entity class.
- Single-entity leaks are easier to miss than list leaks, so Switon keeps the ownership check on both paths.
- `Switon\Principal\IdentityInterface` gives these pieces one shared source of current-identity data.

## One action-level ownership rule {#action-ownership-rule}

`Switon\Http\Attribute\IdentityFilter` is a method attribute for "only my own resources" actions.

By default it uses the `created_by` field. You can also pass another verified field name such as `admin_id` when that is the ownership column your entity and input type already use.

The important part is not the field name itself. The important part is that one action-level declaration becomes a server-owned rule instead of a client suggestion.

## List and query scoping {#list-query-scoping}

On list and search actions, `Switon\Http\CriteriaResolver` applies `Switon\Http\Attribute\IdentityFilter` to `Switon\Http\Criteria`.

That means:

- the current identity id is added as a filter
- a client-supplied ownership filter is overwritten by the server-owned value

This keeps list scoping simple: the action declares ownership once, and the request cannot widen that scope by changing `created_by`, `admin_id`, or another verified ownership field in incoming query filters.

## Single-entity loading, edit, and delete scoping {#single-delete-scoping}

The same ownership model also covers entity parameter binding through `Switon\Orm\EntityResolver`.

When an action parameter is a `Switon\Orm\Entity` subclass and the request includes a primary key, the resolver does not load by primary key alone when the entity declares an ownership field through `#[Switon\Orm\Attribute\Owner]`. It builds the lookup with both:

- the primary key
- the ownership field constrained to the current identity from `Switon\Principal\IdentityInterface::getId()`

Use `#[Owner]` for the default `created_by` column, another field name when ownership uses a different column, or `#[Owner(null)]` to load by primary key only (for example admin actions that use explicit repository lookups instead).

In practice, the existing row must match both the requested id and the current owner. If not, the load fails through `Switon\Orm\RepositoryInterface::firstOrFail()` and ends at `Switon\Orm\Exception\EntityNotFoundException`.

For create and update-style payloads, the resolver also forces the ownership field in the bound entity data before hydration. The action does not need a separate "trust this incoming owner id" branch.

## Why single-entity leaks are harder to notice {#leaks-harder-notice}

List leaks are usually noisy. A user sees rows that obviously do not belong to them.

Single-entity leaks are quieter. An endpoint that loads, edits, or deletes one record by id can still look correct in normal testing, because it returns one plausible object instead of an obviously wrong list. That makes missing ownership checks easier to ship and harder to spot.

Switon's ownership model matters here because it does not stop at list filters. Entity binding applies the same ownership column through `#[Owner]` when the action loads one record by id.

## Why `Switon\Principal\IdentityInterface` makes this possible {#identityinterface-makes-possible}

The unifying piece is `Switon\Principal\IdentityInterface`.

`Switon\Http\Attribute\IdentityFilter` reads the current identity through `IdentityInterface::getId()`. The same normalized contract is also used elsewhere for authenticated state and roles through `isGuest()`, `getName()`, and `getRoles()`.

That matters for ownership safety because HTTP query helpers, typed input binding, ORM entity binding, authorization, and other cross-cutting code do not need to know whether the app authenticated the request with a session, token, or another mechanism. They only need one stable identity contract.

Without that shared contract, ownership rules tend to split into duplicated per-layer logic. With it, Switon can keep the rule small: resolve the current identity once, then reuse it consistently at multiple boundaries.

## Practical takeaway {#practical-takeaway}

If an action should mean "this user can only touch their own records", declare list scope with `Switon\Http\Attribute\IdentityFilter`, keep the same ownership column on the entity with `#[Switon\Orm\Attribute\Owner]`, and set ownership on create paths in application code rather than from request input.

That is the main safety property: list endpoints and single-record endpoints follow the same identity-aware ownership column instead of drifting into separate, easier-to-miss checks.

## See also {#see-also}

- [Typed Input Binding](../http/typed-input-binding.md#typed-input-binding) — request input and entity parameter binding
- [HTTP Package](../http/README.md) — request, controller, and input boundaries
- [Authz Package](../authz/README.md) — authorization beyond ownership scoping
- [Switon fundamentals](../concepts/README.md#switon-fundamentals) — framework model and code-placement context
