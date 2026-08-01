# Design Rationale {#design-rationale}
`switon/captcha` standardizes one challenge-and-verify boundary so the same flow works for HTTP APIs, SPAs, and classic forms without sticky PHP sessions.

## See also {#see-also}

- [Captcha Package](README.md) — usage, configuration, and operating decisions
- [FAQ](faq.md#captcha-faq) — common questions and troubleshooting

---

## Core Problem {#core-problem}

CAPTCHA flows in PHP apps drift in predictable ways:

- the answer lives in `$_SESSION`, so SPAs and JSON APIs cannot verify it without sticky sessions;
- each controller invents its own field names, TTL, and retry rules;
- the renderer (Imagick vs GD) leaks into call sites;
- error semantics differ between mismatch, expiry, and missing input.

The framework needs one boundary that issues a challenge and verifies a proof, with no controller doing it differently from the next.

## Primary Goal {#primary-goal}

Expose one public contract — `CaptchaInterface::create()` and `CaptchaInterface::verify(CaptchaProof)` — backed by a shared store.

- **Benefit:** any worker can verify any issued challenge as long as it shares the store.
- **Benefit:** SPAs, classic forms, and JSON APIs use the same field pair (`captcha_key`, `captcha_code`).
- **Cost:** the deployment must provide a shared store; sessionless UX still depends on networked state.

## Core Choice {#core-choice}

The browser carries `key` only; the answer never leaves the server.

- `create()` writes `code`, `created_at`, `ttl`, and remaining `attempts` to `StoreInterface`.
- `verify()` reads the entry once, runs frequency, expiry, and attempt checks, then compares `code` case-insensitively.
- `CaptchaRequired` and `CaptchaProofResolver` map HTTP input to `CaptchaProof` so call sites stay free of input plumbing.

That keeps the verification path short and keeps controller code free of session affinity, store specifics, and renderer differences.

## Trade-Off {#trade-off}

Both mismatches and successful matches consume one attempt; only `not_found`, `too_frequent`, `expired`, and `attempts_exhausted` short-circuit.

- **Benefit:** brute-force guessing costs the same as guessing right; refresh-after-deny becomes the natural UX.
- **Cost:** a flaky network can spend an attempt on a successful submit the user does not see; the `attempts` default of `1` makes that visible early.

## Deliberate Simplicity {#deliberate-simplicity}

Renderer selection happens once at construction (Imagick preferred, GD fallback). The package does not model handler trees, multi-image layouts, or audio CAPTCHAs in the default contract.

- the public surface stays `CaptchaInterface` with two methods;
- knobs that only one adapter supports (for example `distort` for Imagick) are tolerated, not normalized;
- field-level error semantics route through `InvalidCaptchaException` so one exception path covers UX.
