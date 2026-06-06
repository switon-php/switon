# Skills (beacon / app-facing)

This directory contains **built-in app-facing skills** shipped by `switon/beacon`. These skills use the `switon-` prefix
because they are framework-provided, but they are for building, debugging, and designing Switon-based applications.
Scope is determined by path, not prefix alone: `packages/beacon/skills/` is Beacon app-facing and uses `switon-`.

This separation avoids clashes with app-private skills, which use the `app-` prefix and live in the application's root
`skills/` directory.

One folder per skill, each with `SKILL.md` (frontmatter `name`, `description`). Agents load skills by matching the task
to `description`. See [Agent Skills](https://agentskills.io/) for the format.

## Skill Schema {#skill-schema}

For the `SKILL.md` shape and authoring rules, see [Skill Schema](skill-schema.md).

## Task routing

- **App feature implementation and code reading** → `switon-php-coding`
- **Sword template authoring and cleanup** → `switon-sword`
- **API contract design** → `switon-api`
- **Runtime troubleshooting** → `switon-troubleshooting`
- **App config diagnostics** → `switon-config-check`
- **Creating or updating built-in skills** → `switon-meta-skill`
