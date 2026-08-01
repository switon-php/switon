# Design Rationale {#design-rationale}
`switon/jwt` keeps one strict token boundary for app-owned HMAC tokens instead of growing into a broad JWT platform.

## See also {#see-also}

- [JWT Package](README.md) — usage, configuration, and keys
- [FAQ](faq.md#jwt-faq) — short answers and troubleshooting

## Core Problem {#core-problem}

Token handling drifts when one path validates only part of a token and another path treats claims as trusted too early. The boundary between "signed bytes" and "trusted claims" gets blurry, and application code starts duplicating partial checks.

## Primary Goal {#primary-goal}

The package keeps one validated claim boundary for application-issued tokens.

- **Benefit:** callers get one trusted claims result and typed failures.
- **Benefit:** `decode()` is the only place where token data becomes trusted input.
- **Cost:** public-key federation and broader JWT platform features stay outside the package.

## Core Choices {#core-choices}

- **HMAC only:** `HS256`, `HS384`, and `HS512` cover the common app-owned token case.
- **One boundary:** `decode()` validates structure, header, signature, and time claims before returning claims.
- **Token family routing:** key routing stays explicit at the token boundary through `kid`.
- **Typed failures:** malformed, signature, expiration, not-before, algorithm, and `kid` mismatches stay separate.

## Trade-Offs {#trade-offs}

- direct callers get a small API, but no partial verification path
- key routing stays explicit, but the caller must choose token families on purpose
- claims stay easy to consume, but refresh tokens and broader auth systems belong elsewhere
