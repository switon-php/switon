# Design Rationale {#design-rationale}

`switon/agency` runs the LLM agent loop — sessions, deliveries, and delegation — instead of scattering that orchestration across app services. Profiles and prompt rendering live in this package. Desks live in `switon/desk`. The tool registry lives in `switon/tooling`.

## See also {#see-also}

- [Agency Package](README.md) — sessions, prompts, and usage
- [FAQ](faq.md#agency-faq) — common questions

---

## Core Problem {#core-problem}

Agent features tend to grow entangled with app code.

- Ad hoc LLM calls without a shared run loop or tool boundary
- System prompts bound to runtime types and PHP snippets
- HTML view engines pulled in for plain-text system prompts

That makes agent behavior hard to test, hard to resume, and hard to reason about across a host and its workers.

## Primary Goal {#primary-goal}

One agent runtime with a clear split:

- **Agent** — conversation, run loop, and delegation. `Charters` assembles that delivery's prompt.
- **Profiles** — scan `@agency/agents` into a `Profile` for `Agent::profile()` and `switchProfile()`.
- **PromptRenderer** — interpret template syntax over plain arrays.
- **Root session** — `threads->create()`, `make(Agent)` with `thread`, `profile`, and `workspace`, then `start()`. `profileId` lands in `AgentState::$data`.

- **Benefit:** templates stay plain strings. Tests pass arrays without booting an inbox.
- **Benefit:** the agent owns orchestration. `PromptRenderer` owns syntax only.
- **Cost:** no includes, macros, or expression language. Callers precompute data.

## Core Choice {#core-choice}

Interpret the template in memory on each call. There is no compile-to-PHP step and no view cache.

- **`{% if %}`, `{% for %}`, `{% table %}`, `{{ }}`, `{# #}`** are the whole language.
- **`{% if item in tags %}`** keeps the left side a literal name, so unrelated lists stay separate in `$data`.
- **Invalid block tags** fail at render time with `Switon\Ai\Exception`.
- **Unknown placeholders** stay literal, so JSON braces in a prompt do not break rendering.

`{% %}` is deliberate: prompt templates stay visually distinct from Sword `@` directives.

## Skill Discovery {#skill-discovery}

A skill's id and description are enough to decide when it may help. Its body loads only after activation.

- **Profile advertising** — `Profile::$skills`, assembled by `Charters`.
- **User addressing** — `[label](skill://id)` makes host intent deterministic for one turn.
- **Catalog search** — `skill_list` returns matching ids and descriptions.

`Profile::$skills` is advertisement, not authorization. Loading and script execution still follow the host's skill services.

## Trade-Off {#trade-off}

Unknown `{{ placeholders }}` stay literal instead of raising.

- **Benefit:** JSON examples and prose with braces do not break rendering.
- **Benefit:** a partial template can ship before every key is wired.
- **Cost:** a typo in a variable name shows up only in review or tests.

Block tags are strict. Unclosed or unknown tags raise `Switon\Ai\Exception`.

## Deliberate Simplicity {#deliberate-simplicity}

Domain prose stays in PHP. The template only chooses when to include a prebuilt section.

That keeps the language small and leaves domain rules in typed PHP, where they are easier to diff and test. HTML compilation, themes, `{% else %}`, filters, and inheritance stay outside this package.
