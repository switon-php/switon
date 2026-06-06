# Arcus

Monorepo-local Switon application used for API and CLI development against path-linked `packages/*`.

**Baseline:** `packages/skeleton` is the published app template; Arcus tracks it but uses Composer path repositories for local package development.

**Usage (monorepo):** Work in this directory (or copy `packages/skeleton` for a standalone app). Run `composer install`, then use the entries below.

**Config:** Prefer root `switon.yml`; add `config/*.php` only for PHP-only values or pre-built instances.

**API:** Web entry is `public/index.php`; actions returning arrays are sent as JSON. Example: `GET /api/` → `{"message":"Welcome to Switon"}`.

**CLI:** Run `bash bin/console` (fallback: `php switon.php` on Windows when shell wrapper execution is unavailable); add commands under `app/Command/` and register in config.

**Structure:** `switon.yml`, optional `config/`, `app/` (controllers), `public/`, `skills/`. Skills are first-class (same level as `app/`); one folder per skill, single level. To create or update an app skill, install `switon/beacon` and use `vendor/switon/beacon/skills/switon-meta-skill/SKILL.md`. Add areas, entities, and filters as needed.

**Docs:** https://docs.switon.dev
