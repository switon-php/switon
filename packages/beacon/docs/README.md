# Beacon Package {#beacon-package}
`switon/beacon` is the installable **app-facing** bundle for Switon: packaged AI rules, skills, navigation to standards, and CLI discovery tools in one Composer package.

**Key features:**
- **App-facing skills:** reusable skills ship under `vendor/switon/beacon/skills` after install.
- **Standards pointer:** packaged `vendor/switon/beacon/standards` points agents at how to reach the app root `AGENTS.md`.
- **Composer delivery:** update rules and skills through normal package upgrades.
- **Switon diagnostics:** CLI discovery includes `tool:*`, `config:*`, `class:*`, `di:*`, and `event:*` (among others).

## Installation {#installation}

**Requirements:** `PHP 8.3+`

```bash
composer require --dev switon/beacon
```

## Quick Start {#quick-start}

Install Beacon, then aim your agent bootstrap at the installed vendor package.

```bash
bash bin/console tool:list
bash bin/console tool:entry event
bash bin/console config:check switon.yml config
```

- Read skills from `vendor/switon/beacon/skills`.
- Use `vendor/switon/beacon/standards` as the bridge to your app root `AGENTS.md` rules.
- Framework-development skills under the monorepo `skills/` tree stay **outside** the installed Beacon package.
- `tool:list` prints short `#[Tool]` summaries only.
- `tool:commands` prints the full CLI command surface.

## Terms at a Glance {#terms}

- **skills:** agent skills shipped with the package.
- **standards:** packaged bootstrap pointer to the app root `AGENTS.md`.
- **tool:\*** JSON discovery for the app CLI surface.
- **AGENTS.md:** the usual place to wire an app to Beacon.

## Configuration {#configuration}

Beacon's AI bootstrap layer is loaded from **agent bootstrap** files, not from `switon.yml`.
The PHP package itself still registers its commands and listener through Composer package metadata.

- app root `AGENTS.md`
- app-local agent / IDE configuration
- vendor path resolution in your tooling

After install, run `bash bin/console tool:commands` to see the full command catalog.

Tool invocations also produce a **masked** audit log at `@runtime/beacon/tool.log`.

## Keep in Mind {#keep-in-mind}

- Point agents at the **installed** `vendor/switon/beacon/` tree, not ad-hoc copies of monorepo `skills/` files.
- Treat Beacon as an **AI bootstrap bundle**, not a general-purpose PHP runtime library.
- Install Beacon **once per app**; avoid vendoring a second copy by hand.

## Next {#next}

- **Common questions:** [FAQ](faq.md#beacon-faq)
- **Package boundary:** [Design Rationale](design.md#design-rationale)
