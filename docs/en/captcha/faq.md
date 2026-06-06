# Captcha FAQ {#captcha-faq}
Short answers for setup, configuration, and verification issues.

> **Prerequisites:** [Captcha Package](README.md)

## Getting Started {#getting-started}

### How do I add CAPTCHA to a form? {#add-captcha}

Inject `Switon\Captcha\CaptchaInterface`, expose `create()`, and put `#[Switon\Captcha\Attribute\CaptchaRequired]` on the protected action. See [Quick Start](README.md#quick-start).

### What does `create()` return? {#return-value}

`['key' => '<uuid>', 'blob' => 'data:image/jpeg;base64,...']`. Render `blob` directly and post `key` back as `captcha_key`.

### Which fields must the client post? {#request-fields}

`captcha_key` plus `captcha_code`. The guard reads both from POST input. Bind `Switon\Captcha\CaptchaProof` in the action signature when you want missing fields to surface as `CaptchaProofRequiredException` (HTTP 400) instead of `CaptchaNotFoundException`.

## Configuration {#configuration}

### Where is CAPTCHA configured? {#where-to-configure}

Configure `Switon\Captcha\CaptchaInterface` in `switon.yml`. Touch `Switon\Captcha\StoreInterface` only to change backend connection or prefix. See [Configuration](README.md#configuration).

### How do I change image size or TTL? {#size-and-ttl}

Pass them per call: `create($width = 120, $height = 40, $ttl = 300, ?string $tag = null)`. Adjust the underlying defaults via the `CaptchaInterface` config block.

### How do I load custom fonts? {#fonts}

Set `fonts` on `Switon\Captcha\CaptchaInterface` to a list of TTF paths. Every PHP host must have those files at runtime.

### Can I swap the persistence implementation? {#different-store}

Yes. Implement `Switon\Captcha\StoreInterface` and rebind it. The contract requires the payload to round-trip `code`, `created_at`, `ttl`, and optional `attempts`.

## Behavior and Errors {#behavior-and-errors}

### Which exception should I catch? {#which-exceptions}

Catch `Switon\Captcha\Exception\InvalidCaptchaException` to handle every normal user-facing failure with one branch. The framework still maps subtypes to validation-style payloads. Catch `CaptchaProofRequiredException` separately only when you bind `CaptchaProof` and need to distinguish missing input from a bad code.

### Why do CAPTCHA failures return HTTP 200? {#failure-http-200}

`InvalidCaptchaException` returns HTTP 200 with `{code: -1, msg: 'captcha.invalid', field: 'captcha'}` so the failure renders as a field-level validation error. Override `getStatusCode()` in a custom subclass when your API requires a 4xx code.

### Do wrong codes consume attempts? {#attempt-consumption}

Yes — both mismatches and successful matches consume one attempt. The pre-checks `not_found`, `too_frequent`, `expired`, and `attempts_exhausted` short-circuit before the counter changes. See [Attempt semantics](README.md#attempt-semantics).

### Which renderer will I get? {#which-backend}

Imagick is preferred when loaded; GD is the fallback. With neither extension available, the factory raises a runtime error during resolution.

## Troubleshooting {#troubleshooting}

### Verification keeps failing {#not-verifying}

Look at the exception subtype:

- `CaptchaNotFoundException`: store miss (wrong key, TTL expired, store connectivity).
- `CaptchaTooFrequentException`: submit faster than `min_interval`.
- `CaptchaExpiredException`: TTL elapsed before submit.
- `CaptchaAttemptsExhaustedException`: previous wrong codes drained `attempts`.
- `CaptchaMismatchException`: keys exist but the code is wrong.

### The image never renders {#image-not-loading}

Confirm `blob` reaches the `<img src>` unchanged, that `ext-imagick` or `ext-gd` is loaded, and that every `fonts` path is readable in the runtime environment.

### How do I test code that calls `verify()`? {#testing}

Bind a fake `Switon\Captcha\StoreInterface`, stub `Switon\Core\RandomInterface`, and set `min_interval` to `0` so tests can verify immediately after `create()`.
