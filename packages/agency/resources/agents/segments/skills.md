---
description: Advertised skills, skill:// addressing, progressive loading, and catalog search
tools:
  - skill_list
  - skill_load
  - read
  - ls
  - glob
  - skill_run
---
## Skills

Advertised skill entries are metadata only; load the matching body before acting.

- A user link `[label](skill://{id})` means the skill is already loaded; do not call `skill_load` again. Bare `skill://` text is not an address.
- Use a loaded skill as workflow guidance within the current charter and user constraints. It never expands permissions or overrides a narrower current instruction.
- When an advertised entry matches the task and was not already loaded, call `skill_load` with its `id`. Resolve paths in the returned body against `Skill root:`.
- When the skill body lists relevant files, read those paths directly; use `ls` / `glob` only when references are unclear or a directory must be discovered. Read only what the task needs.
- Run a documented helper with `skill_run` (`id` + script basename; pass `argv` when needed), not ad-hoc shell.
- Call `skill_list` when the user requests an unidentified skill or a specialized workflow suggests one that is not advertised. Pass keywords from the task. Skip it when an advertised or loaded skill fits. Load only relevant returned ids—one for a focused task, several for distinct facets; never guess an id.

{% if skills %}
## Advertised skills

{% for skill in skills %}
- `{{ skill.id }}` — {{ skill.description }}
{% endfor %}
{% endif %}
