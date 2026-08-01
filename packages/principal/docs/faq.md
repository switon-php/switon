# Principal FAQ {#principal-faq}
Short answers for identity, tenant state, and boundary ownership.

> **Prerequisites:** [Principal Package](README.md)

## Getting Started {#getting-started}

### Does this package authenticate users? {#authenticate-users}

No. It only carries current identity state after another boundary has resolved it.

### Does this package discover tenants from hosts or headers? {#discover-tenants}

No. It only carries current tenant state after another boundary has resolved it.

### When should I inject `IdentityInterface`? {#inject-identity}

When code needs the current caller, guest state, or normalized roles.

### When should I inject `TenantInterface`? {#inject-tenant}

When code needs the current tenant id or code.

## State {#state}

### What does guest mean? {#guest-meaning}

Guest means no authenticated caller is currently stored. `getId()` and `getName()` raise `NotAuthenticatedException` in this state; `getRoles()` returns `[]`.

### What does tenantless mean? {#tenantless-meaning}

Tenantless means no tenant is currently stored. `getId()` and `getCode()` raise `TenantNotAvailableException` in this state.

### What do `set()` and `clear()` do? {#set-clear}

`set()` stores resolved runtime state. `clear()` returns the contract to guest or tenantless state.

`Identity::set()` trims and normalizes roles into `list<string>`. `Tenant::set()` trims the tenant code before storing it.

### Can `IdentityInterface` and `TenantInterface` hold different values at the same time? {#independent-state}

Yes. They are separate runtime contracts.
