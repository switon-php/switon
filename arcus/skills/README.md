# Skills (app)

This directory is for **app skills** only. Use the **`app-`** prefix for folder and frontmatter `name` (e.g. `app-invoice`, `app-report`) to avoid clashes with built-in Switon skills.

**Built-in Switon skills** live in `vendor/switon/beacon/skills/` and use the **`switon-`** prefix. These are app-facing built-ins (for example `switon-php-coding`, `switon-api`, `switon-meta-skill`).

Framework-development skills for working on the Switon monorepo live in that repository's root `skills/` directory, use the **`sps-`** prefix, and are **not** part of normal app installs.

Install or update built-in Switon skills with:

```bash
composer require switon/beacon --dev
# or
composer update switon/beacon
```

To **create or update** app skills, use the meta-skill: open `vendor/switon/beacon/skills/switon-meta-skill/SKILL.md` (after installing beacon) or see the [Agent Skills](https://agentskills.io/) format. Default: new skills are app skills in this directory with `app-` prefix.
