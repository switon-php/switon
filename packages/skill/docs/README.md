# Skill Package {#skill-package}

`switon/skill` reads `SKILL.md` directories into one catalog and turns an id into a block the model can see. Choose it when skills should be files on disk, plus rows a desk publishes at runtime. The agent loop stays outside this package.

**Key features:**

- **One directory, one skill:** each child of a configured path that contains `SKILL.md` is a row. The id is frontmatter `id`, or the directory name.
- **Lookup and a block:** `get` fails when the id is missing. `block` still returns a block that names the unknown id.
- **Refresh keeps runtime rows:** `reload` replaces ids found on disk. Rows that are not on those paths stay. Removed files stay until the process restarts.
- **Links and tools:** `[label](skill://id)` pins the block on the user message. `skill_list` lists ids and descriptions. `skill_load` returns that block. `skill_run` runs one file under that skill's `scripts/`.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/skill
```

The agent loop is not part of this package. `skill_list`, `skill_load`, and `skill_run` are registered through `extra.switon.tools`.

## Quick Start {#quick-start}

`@switon/skill/resources/skills`, `@root/skills`, and `~/.*/skills` are the default paths, in that order. The first ships `create-skill`. `~/.*/skills` picks up agent user catalogs (`~/.cursor/skills`, `~/.codex/skills`, `~/.switon/skills`, … — not `skills-cursor`). One child directory is one skill; a later path wins on the same id.

```markdown
---
id: demo
description: Demo skill
---
Do the thing. Scripts live under scripts/.
```

`demo/scripts/hello.php` is run with `skill_run` by id `demo` and script `hello.php`.

## Core Concepts {#core-concepts}

### Row {#row}

A row is an id, a description, the markdown body, and the directory they came from. Frontmatter without an `id` uses the directory name. A file with no frontmatter is skipped. `load` returns nothing when `SKILL.md` is missing or has no frontmatter.

`set` publishes a row under its id. The last write wins, including over a scanned row. `reload` does not drop that row.

### Block {#block}

`block` is the text pinned for the model: a `[skill:id]` header, the skill root, and the body. A missing id returns the same shape with `Unknown skill id`. `get` is the lookup that fails closed.

### Link {#link}

`[label](skill://id)` in the user ask pins that block and leaves the link in place. The id is one segment, or `desk.leaf`. A longer path is not a skill link. The ask is the only part scanned.

### List {#list}

`skill_list` returns a list of `{id, description}`. `skill:list` prints the id, the skill directory, and a short description. `keywords` is optional. A keyword matches the id or the description, ignoring case. Any one keyword is enough. Omit `keywords` to list the whole catalog. An empty result is `[]`.

### Run {#run}

`skill_run` takes `id` and `script`. `script` is a file name under that skill's `scripts/` directory, not a path. `argv` is optional. The process working directory is the agent workspace. `php`, `py`, `sh`, `bash`, `js`, and `mjs` pick an interpreter. Any other file is executed as itself.

## Configuration {#configuration}

Three surfaces are configurable: which directories are scanned, what `skill_run` may execute, and how the CLI lists.

`paths` **appends to** the three defaults, so list only the extra directories:

```yaml
# switon.yml
Switon\Skill\SkillsInterface:
  paths:
    - '@app/skills'
```

`skill_run` can be turned off, or limited to a list of ids. An empty list allows every configured skill.

```yaml
Switon\Skill\Tool\SkillRunTool:
  enabled: true
  allowedSkills: []
```

`skill:list` takes `--keyword`, a comma-separated list matching id or description. Omit it to print the whole catalog.

```bash
bash bin/console skill:list --keyword=pdf,desk
```

### Option Discovery {#option-discovery}

Run:

```bash
bash bin/console config:options 'Switon\Skill\SkillsInterface'
bash bin/console config:options 'Switon\Skill\Tool\SkillRunTool'
```

## Keep in Mind {#keep-in-mind}

- `get` throws for a missing id. `block` and a `skill://` link do not.
- `reload` merges. It does not forget a row that is no longer on disk.
- `skill_run` refuses a script name that contains a path.

## Next {#next}

- **To debug paths, links, lists, and runs:** [FAQ](faq.md#skill-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
