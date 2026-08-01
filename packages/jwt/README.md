# Switon JWT Package

[![JWT CI](https://img.shields.io/github/actions/workflow/status/switon-php/jwt/ci.yml?branch=main&label=JWT%20CI)](https://github.com/switon-php/jwt/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's JWT contract for application services that want HMAC-signed tokens, `kid`-based key selection, and `exp`/`nbf`
checks.

## Highlights

- **Token issuing and validation:** `JwtInterface` covers both sides of the JWT flow.
- **Per-token signing keys:** tokens can carry a key ID, and decode can use it to select the matching signing key.
- **Claim policy:** issued tokens get consistent `exp` and `nbf` claims.
- **Validation policy:** signatures and standard claim checks are enforced together.
- **Clear failure types:** invalid tokens surface as specific exceptions.

## Installation

```bash
composer require switon/jwt
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Jwt\JwtInterface;

class AuthService
{
    #[Autowired] protected JwtInterface $jwt;

    public function issueForTenant(int $userId, string $kid): string
    {
        return $this->jwt->encode(['user_id' => $userId], 3600, $kid);
    }

    public function authenticate(string $token, string $kid): array
    {
        return $this->jwt->decode($token, $kid);
    }
}
```

Docs: https://github.com/switon-php/jwt/blob/main/docs/README.md

## License

MIT.
