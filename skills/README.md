# Skills (framework development)

This directory is for **framework-development skills** in the Switon monorepo. These skills are for developing or maintaining Switon itself: package work under `packages/*`, standards and PHPDoc upkeep, cross-package navigation, and AI discoverability maintenance. They are not for app development, app-private skills, or Beacon built-in app-facing skills.

Use the **`sps-`** prefix for folder and frontmatter `name`. Folder name and frontmatter `name` must match. The prefix alone does not determine scope: repo-root `skills/` means framework-development and uses `sps-`, while `packages/beacon/skills/` means Beacon app-facing built-ins and uses `switon-`.

This repo now has three skill scopes:

- **Repo-root `skills/`** — framework-development skills for the Switon monorepo.
- **`packages/beacon/skills/`** — built-in **app-facing** skills shipped by `switon/beacon`.
- **App project root `skills/`** — app-private skills using the `app-` prefix.

## Task routing

- **Unified docs audit router (one entry; three gates: correctness / execution / quality)** → `sps-audit-docs`
- **Doc proofs audit (doc assertions, proof routing, skill-owned proof scripts, runnable proof-app policy)** → `sps-audit-doc-proofs`
- **Docs correctness gate (truth first: SSOT, drift, identifier proof, learning-doc correctness)** → `sps-audit-docs-correctness`
- **Docs execution gate (prove tutorial / guide / getting-started paths can actually work)** → `sps-audit-docs-execution`
- **Docs quality gate (structure, signal-to-noise, docs UX, user value)** → `sps-audit-docs-quality`
- **Unified UX audit router (one entry for interface/docs/defaults/observability/user-value)** → `sps-audit-ux`
  - Natural trigger examples:
  - `优化 log 的 ux` -> `component=log, mode=observability`
  - `检查 view 文档 ux` -> `component=view, mode=docs`
  - `看看 cache 默认值体验` -> `component=cache, mode=defaults`
  - `看看 authz 文档用户会不会喜欢` -> `component=authz, mode=user-value`
- **Markdown docs** → `sps-docs`
- **Docs SSOT audit (specialized correctness sub-check for English baseline readiness)** → `sps-audit-docs-ssot`
- **Strict identifier verification / anti-hallucination / anti-borrowing** → `sps-verify-identifiers`
  - Default companion for doc verification and correctness skills; stack it automatically when docs work verifies machine-meaningful identifiers.
- **Docs drift audit (read-only, evidence-first)** → `sps-audit-docs-drift`
- **Learning docs correctness audit (strict, read-only; shared validator + evidence checks)** → `sps-audit-learning-doc-correctness`
- **Tutorial executable acceptance (shared validator + runnable proof)** → `sps-audit-tutorial-correctness`
- **Docs structure audit (internal quality sub-check)** → `sps-audit-docs-structure`
- **Docs signal-to-noise audit (internal quality sub-check)** → `sps-audit-docs-snr`
- **Interface UX audit (user-facing API ergonomics)** → `sps-audit-interface-ux`
- **Docs UX audit (onboarding, decision flow, recoverability)** → `sps-audit-docs-ux`
- **Docs user-value audit (whether users will actually like and benefit from the docs)** → `sps-audit-docs-user-value`
- **Defaults UX audit (first-run defaults and config burden)** → `sps-audit-defaults-ux`
- **Observability UX audit (diagnostics and troubleshooting speed)** → `sps-audit-observability-ux`
- **Multilingual docs localization / 多语言文档本地化** → `sps-docs-l10n`
- **Dual-agent doc review / 双代理文档审查** → `sps-dual-doc-review`
- **PHPDoc authoring and optimization** → `sps-phpdoc`
- **`@see` mesh auditing and repair** → `sps-phpdoc-see-mesh` (existence check: `php skills/sps-phpdoc-see-mesh/scripts/validate-see-exists.php` from repo root)
- **Package dependency maintenance** → `sps-packages-deps`
- **PHPStan / extension-installer / package `phpstan.neon`** → `standards/PHPSTAN_STANDARDS.md`
- **Spec-first framework package work** → `sps-package-specflow`
- **Single-package release / split publish / package release readiness** → `sps-package-release`
- **Clean untracked artifacts under component `packages/*/tests` and `packages/*/vendor`** → `sps-component-tests-clean`
- **AI discoverability and cross-package navigation maintenance** → `sps-framework-optimize`
