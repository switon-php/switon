# Agency FAQ {#agency-faq}

Short answers for sessions, prompt templates, and the boundary with HTML views.

> **Prerequisites:** [Agency Package](README.md)

## Getting Started {#getting-started}

### When should I use `Agent` instead of `Instructor`? {#host-vs-instructor}

Use `Agent` for an inbox, more than one turn, tool loops, and a durable thread. Use `Instructor::instruct()` for one synchronous turn that exits through a response envelope. See [Agent sessions](README.md#agent-sessions).

### When should I use `PromptRenderer` instead of Sword or `.phtml`? {#vs-view}

Use `PromptRenderer` for plain text assembled in memory. Use `switon/view` and `switon/renderer` for HTML pages.

### Do system prompts have to live in files? {#files}

Production agents load a `Profile` with `ProfilesInterface::get($id)` from `@agency/agents`. `Charters` merges that profile with its segment ids. Tests may pass a `Profile` into `make`.

## Syntax {#syntax}

### Why was `{{ name }}` left unchanged? {#unknown-placeholder}

The key was missing, `null`, or an array or object. Only strings and scalars are substituted. See [System prompts](README.md#system-prompts).

### Can I use `{% else %}` or `{% if not x %}`? {#no-else}

No. Use another `{% if %}` block, or put a boolean in `$data` before rendering.

### What if `{% for %}` is not an array? {#for-non-array}

The body is skipped. Rendering continues, and nothing is thrown.

### Why did rendering throw `Switon\Ai\Exception`? {#block-errors}

The block is unclosed, the tag is unknown, or a closer has no opener. A typo in `{{ }}` does not throw.

## Data {#data}

### What are the `env.*` variables? {#env-builtins}

Every `render()` injects `env.date_line`, `env.workspace`, `env.os`, `env.os.name`, `env.arch`, `env.hostname`, `env.home`, `env.user`, `env.shell`, `env.lang`, `env.locale`, and `env.php`. `$data` overrides a flat `env.*` key. `env.workspace` is that agent's workspace. `env.date_line` is `Y-m-d (D), e`.

### How do dotted keys resolve? {#flat-keys}

An exact key wins, then a nested walk that tries the remaining path as one key at each level. `env.*` builtins occupy the flat `env.*` names, so override those with a flat key.

## Sessions {#sessions}

### Is the rendered system prompt stored on the conversation? {#prompt-persisted}

No. A live session reuses one `Charter` until `invalidate()`. Only user, assistant, and tool messages persist. `state.json` is the `AgentState::$data` bag.

### What happens when a saved thread is opened? {#resume-fork}

The host is loaded and started. Live sub-agents are not restored. Unsettled open tool calls close when the agent starts. `thread_fork` copies the host thread; opening the copy starts it.

### How are skills advertised? {#skills-advertised}

`Profile::$skills`, assembled by `Charters`. A `skills` key on the state bag is not that list. See [Skill discovery](design.md#skill-discovery).
