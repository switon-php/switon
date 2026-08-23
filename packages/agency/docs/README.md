# Agency Package {#agency-package}

`switon/agency` runs LLM agent sessions: one delivery over a conversation, a durable root thread, and delegated workers. Profiles and prompt rendering live here. Desks are `switon/desk`. The tool registry is `switon/tooling`. Thread storage is `switon/thread`.

**Key features:**

- **Run loop:** inbox delivery on `Agent` is `Loop`. `Instructor::instruct()` is one structured turn with a terminal response tool, and no inbox.
- **Sessions:** create a thread, `make(Agent::class)` with `thread`, `profile`, and `workspace`, then `start()`. Resume with `load()` and the same `make` + `start()`.
- **Profiles:** `@agency/agents/*` loads as `Profile` (`prompt`, `tools`, segment ids, skills, model, `maxSteps`).
- **System prompts:** `Charters` merges the profile with its segments. `PromptRenderer` renders that text. The live `Charter` is reused until `invalidate()`.
- **Tools:** a delivery's tool list is assembled by `Charters`. Lookup is `switon/tooling`.

## Installation {#installation}

**Requirements:** PHP 8.3+, `ext-mbstring`, `ext-swoole`

```bash
composer require switon/agency
```

`PromptRendererInterface` resolves to `Switon\Agency\PromptRenderer`. `Agent` is not a container singleton: each session is `make(Agent::class)`.

## Quick Start {#quick-start}

One reply from an installed app:

```bash
bash bin/console agent:run --agent=assist --message="Hello"
```

`agent:chat` is the REPL. `agent:instruct` prints the unwrapped `data` JSON from one structured turn. `agent:prompt` prints one profile's rendered system prompt.

Open the same kind of session in PHP. `assist` is a shipped profile; pass another id when you have one.

```php
use Switon\Agency\Agent;
use Switon\Ai\Envelope;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Thread;
use Switon\Core\Strings;

$profile = $this->profiles->get('assist');
$threadId = Strings::uuid();
$thread = Thread::of($threadId, '@threads/' . $threadId);
$this->threads->create($thread);

$agent = $this->container->make(Agent::class, [
    'thread' => $thread,
    'profile' => $profile,
    'workspace' => getcwd() ?: '',
]);
$agent->thread()->state = ['profileId' => $profile->id];
$this->threads->saveState($agent->thread());
$agent->start();

$turn = $agent->run(Envelope::of($question, $profile->model));
$output = $turn === null ? null : $turn->lastCompletion()->output;
$text = $output instanceof AssistantMessage ? ($output->text() ?? '') : '';
```

`run()` returns `null` when the delivery left no turn. `lastCompletion()` is only called after that check.

## Core Concepts {#core-concepts}

One delivery is a session, then a system prompt, then tools and completion.

### Agent sessions {#agent-sessions}

- **`Agent` with `parent()` null** — the user-facing root. Its thread and `state.json` persist.
- **`Agent` with `parent()` set** — a delegated worker. Its thread id is `{root}.{leaf}`, and its files live under `{root datadir}/subagents/{leaf}/`.
- **`Instructor`** — `instruct()` only. No inbox and no `Loop`.

A live session keeps one `Charter` until `invalidate()` (profile or desk switch). The rendered system prompt is not stored on the conversation. Host `state.json` is the `AgentState::$data` bag. Opening a saved thread reads `profileId` and `make`s `Agent` again. Live delegated agents are not restored; unsettled open tool calls close when the root starts. `thread_fork` copies the host thread.

### Profiles {#profiles}

`ProfilesInterface::get($id)` scans `@agency/agents` and overlays. Segment merge is `Charters`, not `get()`. Tests can pass a `Profile` instance into `make` as `profile`.

A desk qualifies its leaf: `agents/explore.md` under desk `coding` becomes `coding.explore`. `agents/segments/*.md` are reusable prompt segments. `Profiles::$model` and `Profiles::$maxSteps` fill a registry entry that omits them.

### System prompts {#system-prompts}

`PromptRendererInterface::render(AgentInterface $agent, string $prompt = '', array $segments = [], array $data = []): string` places `$segments` before `$prompt`. `$data` wins over variables registered for that call. The agent path's text and data come from `Charters`.

- **`{{ name }}` or `{{ dotted.path }}`** — a string or scalar is substituted. `null`, arrays, and objects leave the placeholder unchanged.
- **`{% if condition %}` … `{% endif %}`** — PHP `(bool)` on the looked-up value. Missing keys are false. There is no `{% else %}` or `{% if not %}`.
- **`{% if item in tags %}`** — `item` is a literal name, not a data lookup. `tags` is a list path.
- **`{% for item in list.path %}` … `{% endfor %}`** — a non-array list emits nothing.
- **`{% table row in rows %}`** — first non-empty line is the header, last is the row template. An empty list emits nothing.
- **`{# comment #}`** — stripped before the model sees the text. The first `#}` closes it.

Unknown `{{ }}` keys stay literal. A malformed or unclosed block raises `Switon\Ai\Exception`.

`env.date_line`, `env.workspace`, `env.os`, `env.user`, and the other `env.*` keys are injected on every render. Override one with a flat `env.*` key in `$data`.

### Tools and completion {#tools}

`Loop` drives model steps until the delivery settles or `Profile::$maxSteps` is reached. The delivery's tool names come from `Charters`. `thread_fork` and `task_deliver` are tools in this package. `task_finish` is a signal, not a tool.

## Keep in Mind {#keep-in-mind}

- HTML pages belong in `switon/view` and `switon/renderer`, not in `PromptRenderer`.
- The live `Charter` is reused until `invalidate()`. It is not conversation state.
- Templates only choose when to include text. Assemble complex data in PHP.
- A `skills` key on the state bag is not the skill advertisement. That list is `Profile::$skills`.

## Next {#next}

- **To debug behavior:** [FAQ](faq.md#agency-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
- **Desks:** https://github.com/switon-php/desk/blob/main/docs/README.md
