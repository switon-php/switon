# Principal Package {#principal-package}
`switon/principal` gives Switon apps one runtime contract for current identity and current tenant state after boundary code has already resolved them. Choose it when business code needs the resolved caller and tenant in one normalized shape, without depending on JWT payloads, sessions, or host/header parsing. The package does not authenticate users and does not discover tenants.

**Key features:**

- **Identity contract:** `Switon\Principal\IdentityInterface` carries the current caller state (id, name, roles).
- **Tenant contract:** `Switon\Principal\TenantInterface` carries the current tenant state (id, code).
- **Guest and tenantless states:** callers can read current state without guessing whether anything was resolved yet.
- **Normalized data:** business code reads stable types instead of source-specific payloads.
- **Boundary-friendly design:** authentication and tenant lookup stay outside this package.

## Installation {#installation}

**Requirements:** PHP 8.3+, `switon/core`

```bash
composer require switon/principal
```

The backing state lives in Switon context objects, so it follows the current request or coroutine runtime instead of acting like a process-wide singleton.

## Quick Start {#quick-start}

Inject the runtime contracts where application code needs the current caller or tenant.

```php
namespace App\Controller;

use Switon\Core\Attribute\Autowired;
use Switon\Principal\IdentityInterface;
use Switon\Principal\TenantInterface;

final class ProfileController
{
    #[Autowired] protected IdentityInterface $identity;
    #[Autowired] protected TenantInterface $tenant;

    public function indexAction(): array
    {
        return [
            'user_id'     => $this->identity->isGuest() ? null : $this->identity->getId(),
            'roles'       => $this->identity->getRoles(),
            'tenant_code' => $this->tenant->hasTenant() ? $this->tenant->getCode() : null,
        ];
    }
}
```

Boundary code (authentication filter, tenant resolver, JWT handler) calls `set()` after it resolves the values. Application code only reads the contract.

## Core Concepts {#core-concepts}

### Identity {#identity}

`Switon\Principal\IdentityInterface` carries the current caller after authentication has resolved it.

- `isGuest()` reports whether any caller is currently stored.
- `getId()` and `getName()` throw `NotAuthenticatedException` while guest.
- `getRoles()` is safe for guests and returns `[]`.
- `set($id, $name, $roles)` stores resolved state; roles are trimmed and normalized into `list<string>`.
- `clear()` returns the contract to guest state.

### Tenant {#tenant}

`Switon\Principal\TenantInterface` carries the current tenant after another boundary has resolved it.

- `hasTenant()` reports whether any tenant is currently stored.
- `getId()` and `getCode()` throw `TenantNotAvailableException` when no tenant is available.
- `set($id, $code)` stores resolved state; the code is trimmed before storing.
- `clear()` returns the contract to tenantless state.

If the application uses one tenant identifier only, `id` and `code` may carry the same value.

### Scope {#scope}

This package owns the consumer-side contracts for resolved principal state. It does not own:

- authentication protocols or session/token parsing
- tenant discovery rules or host/header routing
- user or tenant persistence models

That narrow scope is the point: the contracts stay stable because they describe the shared use boundary, not the source system behind it.

## Keep in Mind {#keep-in-mind}

- Resolve identity and tenant at the boundary that owns them; this package only carries the result.
- Inject `IdentityInterface` and `TenantInterface` where application code needs current principal state.
- Use `clear()` when a request runtime needs to return to guest or tenantless state.
- Identity and tenant contracts are independent and may hold different values at the same time.

## Next {#next}

- **To answer common questions:** [FAQ](faq.md#principal-faq)
- **To understand the design boundary:** [Design Rationale](design.md#design-rationale)
- **To enforce permissions from roles:** [Authorization Package](https://github.com/switon-php/authz/blob/main/docs/README.md)
- **To validate tokens before setting identity:** [JWT Package](https://github.com/switon-php/jwt/blob/main/docs/README.md)
