# Design Rationale {#design-rationale}
`switon/principal` standardizes resolved identity and tenant state without owning authentication or tenant discovery.

## See also {#see-also}

- [Principal Package](README.md) — usage, concepts, and boundaries
- [Principal FAQ](faq.md#principal-faq) — short answers and state questions

## Core Problem {#core-problem}

Most application code does not care how a caller was authenticated or how a tenant was found. It cares about the resolved result.

- who the current caller is
- whether the caller is authenticated
- which roles are available
- whether a tenant is currently resolved
- which tenant id or code scopes the work

Without one shared contract, those facts tend to appear in sessions, tokens, headers, request attributes, or app-specific arrays. The same concept ends up looking different in different places.

## Why These Contracts Exist {#contracts-exist}

`IdentityInterface` and `TenantInterface` give use-side code one stable form for resolved principal state.

That keeps the boundary clear:

- boundary code resolves identity or tenant
- application code reads normalized runtime state
- this package stays out of authentication and discovery policy

## What They Deliberately Do Not Own {#outside}

These contracts do not own:

- authentication protocols
- tenant discovery rules
- transport-specific payloads
- user or tenant persistence models

That narrow scope is the point. The contracts stay stable because they describe the shared use boundary, not the source system behind it.
