# Design Rationale {#design-rationale}
`switon/i18n` keeps locale choice, translation lookup, and missing-key visibility as one request-safe boundary.

## See also {#see-also}

- [I18n Package](README.md) — usage, configuration, and common pitfalls
- [FAQ](faq.md#i18n-faq) — common questions and troubleshooting
- [HTTP Package](../http/README.md) — request-side locale input

---

## Core Problem {#core-problem}

Locale choice usually drifts first. One page reads a cookie, another reads a header, and validators or background
tasks invent their own fallback rules.

Switon i18n exists to keep those choices consistent across request-scoped code.

## Primary Goal {#primary-goal}

The package standardizes one locale source and one translation lookup path before applications add any extra policy.

- **Benefit:** request-local state stays safe in long-running runtimes
- **Benefit:** translation fallback is predictable across app layers
- **Cost:** persistence and preference storage stay in the application

## Per-Request Locale {#request-local-locale}

`Switon\I18n\Locale` stores the current value in per-request context instead of shared worker state.

That keeps one process from leaking language choice into the next request. The trade-off is intentional: if a user
preference should survive the current request, the application must store it separately.

## Flat Locale Files {#flat-locale-files}

Switon uses flat `array<string, string>` locale files instead of nested translation trees.

That keeps keys stable, fallback rules simple, and validation strict. The cost is less visual hierarchy in the files,
which Switon accepts in exchange for clearer lookup behavior.

## Configurable Fallback {#configurable-fallback}

One key resolves through a small candidate chain: current locale, configured fallbacks, language-only fallback locale, then
default locale.

This lets applications ship partial translation coverage without turning fallback into a large policy subsystem.

## Missing Keys {#missing-keys}

A missing key is usually a content gap, not a system failure.

`Switon\I18n\Event\TranslationKeyMissing` keeps the miss visible while translation still returns the key itself. That
is enough for monitoring and keeps the app usable during translation rollout.

## Optional ICU {#optional-icu}

The default placeholder path stays simple. ICU support is available only when the message needs plural or select
logic.

That keeps the common case small and avoids forcing ICU rules onto every message.
