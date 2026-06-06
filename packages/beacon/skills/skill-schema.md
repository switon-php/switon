# Beacon skill schema {#beacon-skill-schema}

Use this page when you need to author or review a Beacon app-facing skill file.

Beacon skills are directories with a required `SKILL.md` file. The `SKILL.md` file uses YAML frontmatter for metadata
and Markdown for the instructions an agent reads after selecting the skill.

**Key features:**

- **One required file** - every skill directory contains `SKILL.md`
- **YAML frontmatter** - `name` and `description` identify when the skill should be used
- **Progressive disclosure** - optional `references/`, `scripts/`, and `assets/` files are loaded only when needed
- **App-facing scope** - Beacon built-in skills use the `switon-` prefix and live under `vendor/switon/beacon/skills`

**Good fit:**

- You are creating an app-facing Switon skill to ship through Beacon.
- You are checking whether a skill has enough metadata for agents to route to it.
- You need the difference between skill metadata, instructions, references, scripts, and assets.

**Non-goals:**

- This page does **not** describe the separate framework-development skill tree.
- This page does **not** define app-private skill naming beyond the app-vendor boundary.
- This page does **not** replace package-specific skill instructions.

## Directory shape {#directory-shape}

A skill is one directory:

```text
skill-name/
  SKILL.md
  references/
  scripts/
  assets/
```

Only `SKILL.md` is required. Use optional directories when the skill needs larger references, reusable scripts, or
static templates.

## `SKILL.md` frontmatter {#skillmd-frontmatter}

The minimum file shape is:

```markdown
---
name: switon-example
description: Use when an app-facing Switon task needs example-specific guidance.
---

Instruction text for the agent.
```

Required fields:

| Field         | Rule                                                                           |
|---------------|--------------------------------------------------------------------------------|
| `name`        | Lowercase letters, numbers, and hyphens; must match the parent directory name  |
| `description` | Clear trigger text that tells the agent what the skill does and when to use it |

Optional fields:

| Field           | Use                                       |
|-----------------|-------------------------------------------|
| `license`       | License name or bundled license reference |
| `compatibility` | Environment or product requirements       |
| `metadata`      | Extra machine-readable metadata           |
| `allowed-tools` | Experimental tool allow-list metadata     |

## Authoring rules {#authoring-rules}

- Keep the `description` specific enough for routing. Include the task, domain, and trigger words an app developer is
  likely to use.
- Keep `SKILL.md` focused. Put long background material in `references/`.
- Reference sibling files with paths relative to the skill root, such as `references/api-errors.md` or
  `scripts/validate.php`.
- Prefer one-level references from `SKILL.md`; avoid chains where one reference requires several more references before
  the agent can act.
- List important scripts in `SKILL.md` so the agent knows they exist and when to run them.

## Beacon naming scope {#beacon-naming-scope}

Beacon built-in app-facing skills use the `switon-` prefix.

Do not confuse these scopes:

| Scope                             | Location                      | Prefix    |
|-----------------------------------|-------------------------------|-----------|
| Beacon built-in app-facing skills | `vendor/switon/beacon/skills` | `switon-` |
| App-private skills                | app root `skills/`            | `app-`    |

## Next {#next}

- **To install and load Beacon skills in an app:** [Skills (beacon / app-facing)](README.md)
- **For common Beacon boundary questions:** [FAQ](faq.md)
