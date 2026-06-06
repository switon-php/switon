# Identity as a framework contract {#identity-as-a-framework-contract}

`Switon\Principal\IdentityInterface` is a framework contract because multiple boundaries need the same resolved caller shape after authentication is finished.

**Why this is a highlight:**
- `IdentityInterface` keeps identity consumption separate from session, token, cookie, or header parsing
- business code can read resolved identity through one stable contract instead of re-implementing transport logic
- authorization, HTTP ownership scoping such as `Switon\Http\Attribute\IdentityFilter`, and other cross-cutting rules can share the same current-caller source
- `Switon\Principal\Identity` stores that state in runtime context, so the contract stays request-scoped instead of transport-scoped

## The framework decision {#the-framework-decision}

Switon treats caller identity as resolved runtime state, not as an auth-package detail.

That is why the contract lives in `Switon\Principal\IdentityInterface` and the default implementation is `Switon\Principal\Identity`.
Authentication code still belongs to the owning HTTP or app boundary, but once that code has identified the caller, the rest of the framework should consume one normalized shape: `isGuest()`, `getId()`, `getName()`, and `getRoles()`.

This keeps the contract stable even when the app changes between session-based auth, JWT-based auth, mixed flows, or another auth mechanism later.

## Why business code should read the contract, not the transport {#code-contract-transport}

Business code usually needs the current caller, not the original transport details.

That means a controller, service, or listener can read `IdentityInterface` directly after the auth layer calls `set()`.
It does not need to re-parse cookies, bearer tokens, request attributes, or app-specific payload shapes just to answer common questions such as:

- who is the current caller
- whether the caller is a guest
- which normalized roles the caller has

This is the main framework value: identity gets resolved once at the request boundary, then reused everywhere else as a normal dependency.

## Why cross-cutting features depend on it {#cutting-features-depend}

Framework features need a shared identity source or they drift into duplicated per-layer rules.

`Switon\Http\Attribute\IdentityFilter` uses `IdentityInterface::getId()` to keep list criteria ownership scoping server-owned at the HTTP boundary.
Authorization uses `IdentityInterface::getRoles()` so `#[Authorize]` and `AuthorizationInterface` can enforce access without knowing how the app logged the caller in.
The same contract also keeps current-user endpoints and other request-wide decisions aligned on one caller model instead of several transport-specific ones.

That is why `IdentityInterface` belongs at framework level: it is the common seam that lets authentication stay pluggable while identity-aware behavior stays consistent.

## See also {#see-also}

- **To load identity at the request boundary:** [Add authentication](../guides/add-authentication.md#add-authentication)
- **To use the shared contract in controller and service access rules:** [Authorization Package](../authz/README.md)
- **To see one HTTP ownership boundary that depends on this contract:** [Identity-aware ownership safety](identity-aware-ownership.md#identity-aware-ownership)
- **To use the owner package for identity and tenant state:** [Principal Package](../principal/README.md)
