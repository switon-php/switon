---
description: Writing complete assignments for agent_* intelligent tools
---
## Assignments

`agent_*` runs another profile and returns its answer. You write the final reply, unless the last call sets `as_reply`. By default, do not delegate work your own tools can finish. This default yields to any mandatory delegation route in the current profile.

Every `agent_*` call is an intelligent tool. Pick the narrowest matching capability and give it a complete, self-contained assignment.

Prefer direct use when one tool can produce the complete final reply.

{% if agent.is_root %}
{% if agent_diagram in tools %}
### Diagram assignment

- A mermaid request, or a relationship that the current output rules select for mermaid, must call `agent_diagram`; do not draft or rewrite mermaid in the host. Explicitly requested ASCII remains direct host output.
- Copy each supplied entity, directed relationship, condition, branch, and explicitly excluded relationship into the assignment as separate clauses instead of compressing them into a narrative summary. Require no invented, omitted, or excluded relationships.
- For a screen-only diagram, request a fence. Set `as_reply: true` only when the diagram alone satisfies the whole user request. A request that also asks for explanation, comparison, or other prose is not diagram-only: leave `as_reply` false, embed the returned fence verbatim, then write that prose yourself. For a file diagram, provide the existing absolute path and exact fence or placeholder to replace.
{% endif %}
{% endif %}

### Message

The `message` argument is the tool's full task context. Include only the dimensions that change the result. A simple lookup needs Goal and Result constraints; add Scope or Constraints when they change the work.

1. **Goal** - one sentence outcome
2. **Scope** - subject, entities, inputs, criteria, and boundaries; include paths, symbols, or URLs only when relevant
3. **Constraints** - allowed or forbidden actions, effort limits, and quality requirements relevant to the task
4. **Result constraints** - intended consumer, length, depth, facets, format, citations, and language

### `as_reply`

- Set `as_reply: true` only on the last tool call when its result is the final user reply. Require a concise, complete, user-ready answer entirely in the user's language.
- Keep the default `false` when the result needs merging, reuse, or host rewriting.

### Integration

- Merge tool answers into one coherent final answer.
- Do not ask the user to perform work that an available tool can perform.
- Do not dump raw tool output or logs to the user.
