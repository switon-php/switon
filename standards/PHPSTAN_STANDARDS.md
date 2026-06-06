# PHPStan standards (Switon packages)

AI-first rules for static analysis across split packages and the monorepo.

## Extension discovery (`phpstan/extension-installer`)

PHPStan 2 loads installed extensions from Composer via [phpstan/extension-installer](https://github.com/phpstan/extension-installer). **Do not** chain `includes:` across Switon packages.

| Role | `composer.json` | `phpstan.neon` |
|------|-----------------|----------------|
| **Provides rules** (core, pool, di, …) | `extra.phpstan.includes` → `phpstan-extension.neon` | Package-local only: `phpstan-package.neon` (stubs, baseline, excludes) if needed |
| **Runs analysis** (app or component repo) | `require-dev`: `phpstan/phpstan`, `phpstan/extension-installer`; `config.allow-plugins.phpstan/extension-installer: true` | `parameters` only (`paths`, `level`, `bootstrapFiles`) — **no** `includes: vendor/.../extension-installer.neon` in PHPStan 2 |

After `composer install`, `vendor/phpstan/extension-installer` regenerates `GeneratedConfig.php`; PHPStan merges all discovered `extra.phpstan` files automatically.

## What goes in `phpstan-extension.neon` vs `phpstan-package.neon`

| File | Published via `extra.phpstan` | Contents |
|------|------------------------------|----------|
| `phpstan-extension.neon` | Yes | `services:` (broker extensions) and **only** cross-cutting `ignoreErrors` safe for every consumer of that package (e.g. `switon/core`: `new.static`, array `callable`, CLI `json_encode`, PhpStorm/`Autowired` maps, `MakerInterface::make`, `method.notFound`, `offsetAccess.nonOffsetAccessible`, defensive `is_*`/comparison noise, common `class-string`/`|false`/`list|false` argument shapes). Use `reportUnmatchedIgnoredErrors: false` when publishing optional ignores. |
| `phpstan-package.neon` | No — include only from this package's `phpstan.neon` | Package-local `ignoreErrors`, `stubFiles`, `baseline`, `excludePaths` (PDO, query/sharding, Redis `info()`, HTTP CSV streams, RPC without queue in vendor, etc.). **Do not** put these in `switon/core`. |

Example: `switon/core` publishes `new.static` + array `callable` ignores; `switon/di` keeps stubs/baseline in `phpstan-package.neon` only.

## Monorepo vs split repository

- **Monorepo root**: `require-dev` `phpstan/extension-installer` + `allow-plugins` once. Run `bash scripts/run-phpstan.sh <package>`.
- **Split component repo**: Same `extension-installer` setup in that repo's root `composer.json`. Installed `switon/*` packages contribute their `extra.phpstan` automatically.
- **Do not** copy `LazyMethods`, `PoolGuard`, or `ContainerMake*` into another package's neon — depend on `switon/core`, `switon/pool`, or `switon/di` instead.

## Adding a new broker extension

1. Implement `Switon\{Package}\PhpStan\*` under `src/PhpStan/`.
2. Register in **that package's** `phpstan-extension.neon` (`services:` + tags).
3. Add `extra.phpstan.includes` in the package `composer.json`.
4. Add package-local stubs/baseline in `phpstan-package.neon` if analysis of that package's `src/` needs them.

## Commands

```bash
# Monorepo (from repo root)
bash scripts/run-phpstan.sh <package>

# Single package (from packages/<name>/, after composer install)
composer phpstan
```

Regenerate extension list after changing `extra.phpstan`:

```bash
composer update phpstan/extension-installer
```
