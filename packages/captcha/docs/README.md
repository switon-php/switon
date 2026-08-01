# Captcha Package {#captcha-package}
`switon/captcha` issues image CAPTCHA challenges and verifies the answers against a shared store, so any worker can verify a challenge issued by any other worker. Choose it for HTTP APIs, SPAs, and classic forms when you want one CAPTCHA flow without sticky PHP sessions.

**Key features:**

- **Sessionless client flow:** `create()` returns `key` and `blob`; the client posts `captcha_key` and `captcha_code` with the protected request.
- **Action guard:** `Switon\Captcha\Attribute\CaptchaRequired` verifies the proof before the controller body runs.
- **Shared-store verification:** answers live in `Switon\Captcha\StoreInterface` (Redis-backed by default), not in PHP session state.
- **Auto-selected renderer:** Imagick when present, GD as fallback; hard fails when neither is available.
- **Typed failures:** verification errors map to `Switon\Captcha\Exception\InvalidCaptchaException` subtypes; missing input maps to `Switon\Captcha\Exception\CaptchaProofRequiredException`.

## Installation {#installation}

**Requirements:** PHP 8.3+, a shared backing store (Redis by default), and `ext-imagick` or `ext-gd`.

```bash
composer require switon/captcha
```

## Quick Start {#quick-start}

Expose `create()` for the client and guard the protected action with `#[CaptchaRequired]`.

```php
namespace App\Controller;

use Switon\Captcha\Attribute\CaptchaRequired;
use Switon\Captcha\CaptchaInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('')]
class ContactController
{
    #[Autowired] protected CaptchaInterface $captcha;

    #[GetMapping('/captcha')]
    public function captchaAction(): array
    {
        return $this->captcha->create();
    }

    #[PostMapping('/contact')]
    #[CaptchaRequired]
    public function submitAction(): array
    {
        return ['success' => true];
    }
}
```

`blob` is already a `data:image/jpeg;base64,...` URI; assign it directly to `<img src>` and submit `key` back as `captcha_key`:

```js
const payload = await fetch('/captcha').then((r) => r.json());
document.querySelector('#captcha-image').src = payload.blob;
formData.set('captcha_key', payload.key);
```

## Core Concepts {#core-concepts}

The flow is `create()` -> client renders blob -> client posts proof -> guard or resolver runs `verify()`.

### Challenge proof {#challenge-proof}

`CaptchaInterface::create()` returns `['key' => '<uuid>', 'blob' => 'data:image/jpeg;base64,...']`. The client keeps `key`, shows `blob`, and posts the typed pair `captcha_key` + `captcha_code` with the protected request.

The two ways to verify the proof:

- **`#[CaptchaRequired]`** — guard attribute on the action method. Reads `captcha_key` and `captcha_code` from POST input (field names configurable on the attribute) and calls `verify()` before the body runs. Missing input becomes `CaptchaNotFoundException` because empty values still reach the store.
- **`CaptchaProof` parameter** — bind `Switon\Captcha\CaptchaProof` directly in the action signature. The resolver fails fast with `CaptchaProofRequiredException` when `captcha_key` or `captcha_code` is missing or blank, then your code calls `verify()` explicitly.

```php
use Switon\Captcha\CaptchaProof;

$this->captcha->verify(CaptchaProof::of($key, $code));
```

### Shared store {#shared-store}

Only `key` and `blob` travel to the browser. The expected code, `created_at`, `ttl`, and remaining `attempts` live in `StoreInterface`. Any worker that shares the store can verify a challenge created on a different host. The default implementation is Redis-backed; swap it by binding a different `StoreInterface` implementation.

### Attempt semantics {#attempt-semantics}

`verify()` consumes one attempt on **both mismatch and successful match**, so brute force costs the same as guessing right. The pre-checks `not_found`, `data_invalid`, `too_frequent`, `expired`, and `attempts_exhausted` short-circuit before the attempt counter changes. Refresh the challenge after a denial; reusing a key after a wrong answer eventually surfaces `CaptchaAttemptsExhaustedException`.

## Configuration {#configuration}

Defaults work for prototyping. Tune `min_interval` and `attempts` first when you need to harden a flow; touch `StoreInterface` only to change backend or prefix.

```yaml
# switon.yml
Switon\Captcha\CaptchaInterface:
  min_interval: 1.0
  attempts: 2

Switon\Captcha\StoreInterface:
  prefix: 'captcha:prod:'
```

### Option discovery {#option-discovery}

```bash
bash bin/console config:options 'Switon\Captcha\CaptchaInterface'
bash bin/console config:options 'Switon\Captcha\StoreInterface'
```

## Keep in Mind {#keep-in-mind}

- The browser never holds the answer; verification needs the shared store on every worker.
- Catch `InvalidCaptchaException` for normal user-facing failures (mismatch, expiry, exhausted attempts, too frequent). Catch `CaptchaProofRequiredException` only when you bind `CaptchaProof` and want to distinguish missing input from a bad code.
- `distort` is Imagick-only; GD ignores it. Mix renderer-specific knobs with care if your hosts are heterogeneous.
- Code comparison is case-insensitive.

## Next {#next}

- **To debug behavior and defaults:** [FAQ](faq.md#captcha-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
- **Related:** [Validation](../../validation/docs/README.md), [Throttle](../../throttle/docs/README.md), [Redis](../../redis/docs/README.md)
