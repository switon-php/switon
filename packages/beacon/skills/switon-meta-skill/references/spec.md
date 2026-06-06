# Agent Skills Specification (snapshot)

Snapshot from <https://agentskills.io/specification>. Conventions in this skill extend or clarify the spec. Re-sync when
the upstream spec changes.

---

## Directory structure

A skill is a directory containing, at minimum, a `SKILL.md` file:

```
skill-name/
├── SKILL.md          # Required: metadata + instructions
├── scripts/          # Optional: executable code
├── references/       # Optional: documentation
├── assets/           # Optional: templates, resources
└── ...               # Any additional files or directories
```

## `SKILL.md` format

The `SKILL.md` file must contain YAML frontmatter followed by Markdown content.

### Frontmatter

| Field           | Required | Constraints                                                                                                       |
|-----------------|----------|-------------------------------------------------------------------------------------------------------------------|
| `name`          | Yes      | Max 64 characters. Lowercase letters, numbers, and hyphens only. Must not start or end with a hyphen.             |
| `description`   | Yes      | Max 1024 characters. Non-empty. Describes what the skill does and when to use it.                                 |
| `license`       | No       | License name or reference to a bundled license file.                                                              |
| `compatibility` | No       | Max 500 characters. Indicates environment requirements (intended product, system packages, network access, etc.). |
| `metadata`      | No       | Arbitrary key-value mapping for additional metadata.                                                              |
| `allowed-tools` | No       | Space-delimited list of pre-approved tools the skill may use. (Experimental)                                      |

Minimal example:

```markdown
---
name: skill-name
description: A description of what this skill does and when to use it.
---
```

#### `name` field

- Must match the parent directory name
- Must not contain consecutive hyphens (`--`)
- Must not start or end with a hyphen (`-`)
- May only contain unicode lowercase alphanumeric characters (`a-z`) and hyphens (`-`)
- Must be 1-64 characters

#### `description` field

- Should include specific keywords that help agents identify relevant tasks
- Should describe both what the skill does and when to use it
- Must be 1-1024 characters

#### `license`, `compatibility`, `metadata`, `allowed-tools`

Optional. See upstream spec for details.

### Body content

The Markdown body after the frontmatter contains the skill instructions. There are no format restrictions. Recommended:
edge cases, input/output examples, step-by-step instructions. Consider splitting longer content into referenced files.

## Optional directories

- **scripts/** — Executable code agents can run. Handle edge cases; include helpful errors.
- **references/** — Additional documentation loaded on demand. Keep files focused.
- **assets/** — Static resources (data, images, templates).

## Progressive disclosure

1. Metadata (~100 tokens): `name` and `description` loaded at startup for all skills.
2. Instructions: Full `SKILL.md` body loaded when the skill is activated. Keep under 500 lines.
3. Resources: Files in `scripts/`, `references/`, `assets/` loaded only when required.

## File references (same skill)

When referencing other files **inside the same skill**, use **relative paths from the skill directory root**. The agent
resolves these paths from the skill root.

Examples from `SKILL.md`:

```markdown
See [the reference guide](references/REFERENCE.md) for details.

Run the extraction script:
scripts/extract.py
```

- **Depth:** Keep file references **one level deep from `SKILL.md`**. From `SKILL.md` you may link to
  `references/foo.md` or `scripts/bar.py` at skill root. Avoid deeply nested chains (e.g. `references/sub/bar.md` or
  `references/a.md` → `references/b.md` → `references/c.md`).
- **From `references/*.md`:** The same rule applies: paths are relative to the **skill root**. A reference file can link
  to another reference (e.g. `[schema](schema.md)` from `references/guide.md` points to `references/schema.md`) or to a
  script (e.g. `scripts/validate.sh`). Same depth rule: avoid chains that go deeper than one level from `SKILL.md`.

## When to load which file

Tell the agent **when** to load each file so it loads context on demand instead of everything up front. Conditional
guidance is better than a generic "see references/ for details."

- Good: "Read `references/api-errors.md` if the API returns a non-200 status code."
- Weak: "See references/ for more information."

List scripts that exist so the agent knows they are available: e.g. "**`scripts/validate.sh`** — Validates config files.
Run after editing `config/*.php`."

## Referencing scripts

- Paths to scripts are **relative to the skill directory root** (e.g. `scripts/validate.sh`, `scripts/process.py`).
- In `SKILL.md` or in `references/*.md`, use these relative paths in code blocks or instructions. The same convention
  applies in reference files — script paths in `references/*.md` are still relative to the **skill root**, because the
  agent runs commands from the skill directory.

## Cross-skill references

The Agent Skills spec **does not define** how to reference or invoke another skill. Clients and projects define their
own convention.

**This project:** When pointing the agent to another skill (e.g. for a related task), always mention the word **skill**
and that skill's **name** (e.g. "see the **switon-php-coding** skill" or "use the **app-invoice** skill"). That makes it
clear the agent should trigger or load another skill, not open a file path. Do not use a path like
`../switon-php-coding/SKILL.md`; skill discovery is by name, not by path.

## Validation

Use [skills-ref](https://github.com/agentskills/agentskills/tree/main/skills-ref) to validate:
`skills-ref validate ./my-skill`.
