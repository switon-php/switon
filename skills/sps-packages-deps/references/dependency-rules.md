# Dependency Rules and Checklist

## Namespace → Composer package

| PHP namespace (first segment after `Switon\`) | Composer package |
|-----------------------------------------------|------------------|
| Authorizing | switon/authz |
| Caching | switon/cache |
| Captcha | switon/captcha |
| Cli | switon/cli |
| ComposerPlugin | switon/composer-plugin |
| Context | switon/context |
| Core | switon/core |
| Crypto | switon/crypto |
| Db | switon/db |
| Di | switon/di |
| Eventing | switon/event |
| Http | switon/http |
| HttpCache | switon/http-cache |
| HttpClient | switon/http-client |
| Id | switon/id |
| I18n | switon/i18n |
| Inspector | switon/inspector |
| Invoking | switon/invoker |
| Jwt | switon/jwt |
| Kernel | switon/kernel |
| Logging | switon/log |
| Orm | switon/orm |
| Pooling | switon/pool |
| Query | switon/query |
| Rbac | switon/rbac |
| Redis | switon/redis |
| Rendering | switon/renderer |
| Routing | switon/router |
| Session | switon/session |
| Sharding | switon/sharding |
| Sync | switon/sync |
| Testing | switon/testing |
| Throttle | switon/throttle |
| Validating | switon/validation |
| Viewing | switon/view |

Use: when you see `use Switon\Logging\...` → add or check `switon/log` in require.

---

## Common mistakes

- **Wrong:** Adding `switon/foo` to require when the code only uses classes from `switon/bar` (wrong package).
- **Wrong:** Forgetting that `extends` / `implements` and type hints also create a dependency (e.g. implementing `Switon\Core\SomeInterface` requires `switon/core`).
- **Wrong:** Putting a package used in `src/` only in `require-dev` — it must be in `require`.
- **Wrong:** Declaring `ext-*` that the package does not use, or omitting an extension the package actually uses (e.g. Redis client needs `ext-redis` if used at runtime).
- **Wrong:** Adding `ext-swoole` to require for packages that only *optionally* touch Swoole (e.g. `Backtrace`, coroutine helpers) — those must keep a runtime check and omit `ext-swoole` from `require`.

---

## require vs require-dev

- **src/** 里的引用 → 对应包写在 **require**。
- **tests/** 里的引用 → 对应包写在 **require-dev**（若 src 未引用）。

## Checklist before submit

- [ ] 每个 `use Switon\Xxx\` 都有对应 `switon/xxx`：src 的 in require，仅 tests 的 in require-dev。
- [ ] `php` is `>=8.3`; `ext-*` only if the package uses that extension.
- [ ] `switon/*` version: `*` (monorepo) or `^1.0` (released); consistent with other packages.
- [ ] `composer install` (from repo root) succeeds after changes.
