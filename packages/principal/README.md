# Switon Principal Package

[![Principal CI](https://img.shields.io/github/actions/workflow/status/switon-php/principal/ci.yml?branch=main&label=Principal%20CI)](https://github.com/switon-php/principal/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's request-local identity and tenant state for auth checks and tenant-aware flows.

## Highlights

- **Request-scoped identity:** each request keeps its own guest or authenticated user state.
- **Tenant state:** the resolved tenant stays available for later checks without re-resolving it.
- **Explicit boundary writes:** auth and tenant resolution can seed or clear the current request state.
- **Stable tenant key:** apps can keep a public tenant code separate from the internal tenant ID.
- **Mutable request state:** identity and tenant data stay local to the current request.

## Installation

```bash
composer require switon/principal
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Principal\IdentityInterface;
use Switon\Principal\TenantInterface;

final class AuthBoundary
{
    #[Autowired] protected IdentityInterface $identity;
    #[Autowired] protected TenantInterface $tenant;

    public function bootstrap(): void
    {
        $this->identity->set(7, 'mark', ['admin']);
        $this->tenant->set('acme', 'acme');
    }
}

final class ProfileController
{
    #[Autowired] protected IdentityInterface $identity;
    #[Autowired] protected TenantInterface $tenant;

    public function indexAction(): array
    {
        return [
            'user_id' => $this->identity->isGuest() ? null : $this->identity->getId(),
            'tenant' => $this->tenant->hasTenant() ? $this->tenant->getCode() : null,
        ];
    }
}
```

Docs: https://github.com/switon-php/principal/blob/main/docs/README.md

## License

MIT.
