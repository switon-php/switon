# Switon Skill Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/skill/ci.yml?branch=main&label=CI)](https://github.com/switon-php/skill/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's skill catalog for application services that want `SKILL.md` rows, lookup, and a composed block without owning the agent loop.

## Highlights

- **One directory, one skill:** each child directory with `SKILL.md` is a row. The id is the frontmatter field `id`, or the directory name.
- **A block for every id:** a known id includes the root and the body. An unknown id still returns a block that names the id.
- **Links and tools:** `skill://id` in a user message pins that block. `skill_list` lists ids, `skill_load` returns a block, and `skill_run` runs a script.

## Installation

```bash
composer require switon/skill
```

## Quick Start

The default paths are `@switon/skill/resources/skills`, then `@root/skills`, then `~/.switon/skills`. One child directory is one skill. A later path overrides the same id.

```markdown
---
id: demo
description: Demo skill
---
Do the thing.
```

A row published at runtime stays across a rescan. A file removed from disk stays until the process restarts.

Docs: https://github.com/switon-php/skill/blob/main/docs/README.md

## License

MIT.
