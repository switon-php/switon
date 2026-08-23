# Skill FAQ {#skill-faq}

Short answers for skill rows, links, lists, and `skill_run`.

> **Prerequisites:** [Skill Package](README.md)

## Getting Started {#getting-started}

### Where does a skill live? {#where}

Under a configured path, one child directory per skill. The default paths are `@switon/skill/resources/skills`, then `@root/skills`, then `~/.*/skills`. See [Quick Start](README.md#quick-start).

### What if frontmatter has no id? {#id}

The directory name is the id when frontmatter has no `id`. A `SKILL.md` with no frontmatter is skipped. `load` returns nothing when the file is missing or has no frontmatter.

### Does a rescan drop rows? {#reload}

No. Ids found on disk replace the same id. A runtime row that is not on those paths stays. A file deleted from disk stays until the process restarts.

## Lookup {#lookup}

### What happens when the id is unknown? {#unknown}

`get` fails. `block` returns a block whose text says the id is unknown. A `skill://` link pins that same block and keeps the markdown link.

### Which links are read? {#link}

`[label](skill://id)` in the user ask. The id is one segment, or `desk.leaf`. A longer path is left as text.

## List {#list}

### How do I find a skill by keyword? {#list-keywords}

Call `skill_list`, or run `skill:list`. Each keyword is matched against the id and the description, ignoring case. One match is enough. Omit keywords to list every configured skill. The command also prints the skill directory. Load a body with `skill_load`.

```bash
bash bin/console skill:list
bash bin/console skill:list --keyword=pdf,desk
```

## Run {#run}

### What may `skill_run` execute? {#script}

A file name under that skill's `scripts/` directory. A name with a path separator is rejected. The process working directory is the agent workspace. `php`, `py`, `sh`, `bash`, `js`, and `mjs` select an interpreter. Other files are executed directly.

### How do I limit which skills can run? {#limit}

Set `allowedSkills` on `Switon\Skill\Tool\SkillRunTool`. An empty list allows every configured skill. `enabled: false` hides the tool and rejects a call. See [Run](README.md#run).
