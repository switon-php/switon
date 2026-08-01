# JWT Package {#jwt-package}
`switon/jwt` issues and validates HMAC JWTs through one trust boundary. Choose it for app-owned token boundaries where one trust domain shares one algorithm and key policy. Public-key federation, refresh-token policy, and broader auth flows stay outside the package.

**Key features:**

- **One trust boundary:** `decode()` validates structure, header fields, signature, and time claims before claims reach business logic.
- **HMAC signing:** supports `HS256`, `HS384`, and `HS512`.
- **Token family routing:** an optional header `kid` maps to a derived `jwt:{kid}` key for isolated token families.
- **DI-friendly default:** `Switon\Jwt\JwtInterface` resolves to `Switon\Jwt\Jwt` in normal Switon apps.
- **Typed failures:** malformed, expired, not-before, signature, algorithm, and `kid`-mismatch cases fail separately.

## Installation {#installation}

**Requirements:** PHP 8.3+, `switon/core`, `switon/crypto`

```bash
composer require switon/jwt
```

## Quick Start {#quick-start}

Inject `Switon\Jwt\JwtInterface`, issue tokens with `encode()`, and validate them with `decode()`.

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Jwt\JwtInterface;

class AuthService
{
    #[Autowired] protected JwtInterface $jwt;

    public function issueForUser(int $userId): string
    {
        return $this->jwt->encode(['user_id' => $userId], 3600);
    }

    /** @return array<string, mixed> */
    public function readToken(string $token): array
    {
        return $this->jwt->decode($token);
    }
}
```

`encode()` always sets `iat`. It adds `exp` when `ttl > 0` and removes `exp` when `ttl <= 0`. `decode()` only returns claims after validation succeeds.

Use `kid` when different token families need separate keys:

```php
$token  = $this->jwt->encode(['user_id' => 123], 3600, 'tenant:acme:v1');
$claims = $this->jwt->decode($token, 'tenant:acme:v1');
```

## Core Concepts {#core-concepts}

### Trust Boundary {#trust-boundary}

`decode()` is the single trust boundary. It checks token format, header `alg`, optional header `typ`, optional header `kid` type, payload structure, `kid` agreement when both caller and token carry one, signature, and `exp` / `nbf`. Claims become trusted input only after every check passes.

### Time Claims {#time-claims}

`encode()` writes `iat` automatically and writes `exp` when `ttl > 0`. `decode()` rejects `exp` and `nbf` claims that are not numeric. `leeway` (seconds) softens `exp` and `nbf` checks when clocks drift.

### Token Family Routing {#token-family-routing}

Without `kid`, JWT uses the configured `key`, or derives a default key from `jwt` when `key` is null.

With `kid`, JWT derives `jwt:{kid}` instead. Two cases matter at `decode()`:

- when the caller passes an expected `kid` and the token header carries a different `kid`, `decode()` raises `KidMismatchException` before signature verification
- when the caller passes `null` and the token header still carries `kid`, that header drives key resolution and the signature is verified against `jwt:{kid}`

### Failures {#failures}

| Exception | When it fires |
|---|---|
| `NoCredentialException` | empty token string |
| `MalformedException` | broken structure, header, or claim shape |
| `AlgorithmMismatchException` | header `alg` differs from configured algorithm |
| `KidMismatchException` | caller `kid` and token header `kid` disagree |
| `SignatureException` | signature verification fails |
| `ExpiredException` | current time past `exp` (with `leeway`) |
| `NotBeforeException` | current time before `nbf` (with `leeway`) |

## Configuration {#configuration}

```yaml
# switon.yml
Switon\Jwt\JwtInterface:
  alg: HS256
  key: null
  leeway: 0
```

When `key` is set, use lowercase hex with an even number of characters. `key: null` derives the default key from `Switon\Crypto\CipherInterface`. The configured hex length does not have to match the algorithm strength exactly.

### Option Discovery {#option-discovery}

```bash
bash bin/console config:options 'Switon\Jwt\JwtInterface'
```

## Keep in Mind {#keep-in-mind}

- `decode()` is not a signature-only verify path; treat its return value as the only trusted source of claims.
- Use `kid` for token families, not for payload data.
- Validate the JWT first, then apply authorization or business rules to the claims.
- `jwt_encode()` and `jwt_decode()` exist for lightweight code, but services should inject `JwtInterface`. The helpers expect a bootstrapped container because they resolve `JwtInterface` from `App::get()`.

## Next {#next}

- **To read short answers:** [FAQ](faq.md#jwt-faq)
- **To understand the boundary choice:** [Design Rationale](design.md#design-rationale)
- **To enforce permissions on validated claims:** [Authorization Package](../../authz/docs/README.md)
