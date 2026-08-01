#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Seed the switon-agent docfs book via MCP HTTP tools/call.
 */

$base = 'http://127.0.0.1:9520/mcp';
$session = null;
$id = 1;

function rpc(string $base, ?string &$session, int &$id, string $method, array $params = []): array
{
    $payload = ['jsonrpc' => '2.0', 'id' => $id++, 'method' => $method, 'params' => (object) $params];
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
    ];
    if ($session) {
        $headers[] = 'Mcp-Session-Id: ' . $session;
    }

    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $header = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    if (preg_match('/^Mcp-Session-Id:\s*(\S+)/mi', $header, $m)) {
        $session = trim($m[1]);
    }
    // SSE or plain JSON
    $json = $body;
    if (str_contains($body, 'data:')) {
        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'data:')) {
                $json = trim(substr($line, 5));
            }
        }
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("HTTP $code bad body: " . substr($body, 0, 500));
    }
    if (isset($decoded['error'])) {
        throw new RuntimeException(json_encode($decoded['error'], JSON_UNESCAPED_UNICODE));
    }

    return $decoded;
}

function tool(string $base, ?string &$session, int &$id, string $name, array $arguments): array
{
    $res = rpc($base, $session, $id, 'tools/call', [
        'name' => $name,
        'arguments' => $arguments,
    ]);
    $content = $res['result']['content'][0]['text'] ?? null;
    if (!is_string($content)) {
        return $res['result'] ?? $res;
    }
    $parsed = json_decode($content, true);

    return is_array($parsed) ? $parsed : ['raw' => $content];
}

function page(string $title, string $body): string
{
    return "---\ntitle: {$title}\n---\n\n{$body}";
}

rpc($base, $session, $id, 'initialize', [
    'protocolVersion' => '2024-11-05',
    'capabilities' => new stdClass(),
    'clientInfo' => ['name' => 'docfs-seed', 'version' => '0.1.0'],
]);
rpc($base, $session, $id, 'notifications/initialized', []);

echo "session=$session\n";

$books = tool($base, $session, $id, 'list_books', []);
$exists = false;
foreach ($books['entries'] ?? [] as $e) {
    if (($e['book'] ?? '') === 'switon-agent') {
        $exists = true;
        break;
    }
}
if (!$exists) {
    $r = tool($base, $session, $id, 'mkbook', [
        'book' => 'switon-agent',
    ]);
    echo "mkbook: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

$pages = [
    'getting-started' => [
        'Getting Started',
        <<<'MD'
# Getting Started

`switon/agency` is Switon’s **LLM agent runtime**: sessions and `Loop`. Profiles and prompt rendering are `switon/agents`. The tool registry is `switon/tooling`. Thread storage is `switon/thread`.

## Install

```bash
composer require switon/agency
```

Requires PHP 8.3+.

## Minimal host run

```php
use Switon\Agency\Agent;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Envelope;
use Switon\Core\Strings;
use Switon\Ai\Thread;

$profile = $profiles->get('host');
$threadId = Strings::uuid();
$thread = Thread::of($threadId, '@threads/' . $threadId);
$threads->create($thread);
/** @var Agent $host */
$host = $container->make(Agent::class, [
    'thread' => $thread,
    'profile' => $profile,
    'workspace' => getcwd() ?: '',
]);
$host->thread()->state = ['profileId' => $profile->id];
$threads->saveState($host->thread());
$host->start();
$turn = $host->run(Envelope::of('Hello', $container->get(\Switon\Llm\LlmProvidersInterface::class)->model($host->profile()->model)));
$output = $turn->lastCompletion()?->output;
echo $output instanceof AssistantMessage ? ($output->content ?? '') : '';
```

Profiles are Markdown under `@agents`. An omitted `profileId` loads id `host`, which is not shipped; add that file or pass another id. Wire LLM providers via `switon/llm`.

## What to read next

- [Overview](concepts/overview/page.md) — root agent, delegated agent, Instructor
- [Tools](concepts/tools/page.md) — registered tools, plus MCP when `switon/mcp-client` is installed
- [Prompts](concepts/prompts/page.md) — PromptRenderer syntax
MD
    ],
    'concepts/overview' => [
        'Overview',
        <<<'MD'
# Overview

## Domain stack

**thread → turn → step**

- Say **delivery** for one inbox advance (`turnId`, `DeliveryStarted` / `DeliverySettled`)
- Say **run loop** for `Loop` (`Agent::run()` / `Loop::drive()`). `Instructor::instruct()` does not use it.

## Three entry points

| Kind | Role |
|------|------|
| **Agent** (`parent()` null) | User-facing root session; durable thread; holds minted children |
| **Agent** (`parent()` set) | Delegated worker with inbox `deliver`; finishes and retires |
| **Instructor** | Synchronous `instruct()` with structured exit tool; no inbox |

## Facades

| Interface | Job |
|-----------|-----|
| `Agent` | `switon/agency`: root session (`thread`, `profile`, `workspace`) |
| `ThreadsInterface` | `switon/thread`: host snapshot + transcript + state bag |
| `ToolsInterface` | `switon/ai` contract (`expose` / `call`); `Tools` is `switon/tooling` |
| `LoopInterface` | `switon/agency`: step driver over an open turn |
| `PromptRendererInterface` | `switon/ai`: system prompt templates |

`Loop::drive(AgentInterface, Delivery, maxSteps)` runs the step loop. `Charter` holds that delivery's tools and prompt; the conversation lives on the agent. See `Switon\Agency\Charters`.
MD
    ],
    'concepts/sessions' => [
        'Sessions',
        <<<'MD'
# Sessions

## Create / open

```php
$profile = $profiles->get('host');
$thread = Thread::of($threadId, '@threads/' . $threadId);
$threads->create($thread);
$host = $container->make(Agent::class, [
    'thread' => $thread,
    'profile' => $profile,
    'workspace' => getcwd() ?: '',
]);
$host->thread()->state = ['profileId' => $profile->id];
$threads->saveState($host->thread());
$host->start();
$thread = Thread::of($threadId, '@threads/' . $threadId);
$threads->load($thread);
$host = $container->make(Agent::class, [
    'thread' => $thread,
    'workspace' => (string) ($thread->state['workspace'] ?? ''),
    'profile' => $profiles->get($thread->state['profileId']),
]);
$host->start();
```

- **New** — `threads->create()`, `make(Agent::class)` with `thread`, `profile`, `workspace`, write `profileId`, `start()`
- **Open** — `load` the datadir, `make(Agent::class)` from `state.json`, `start()`. **Live sub-agents are not restored**
- **`thread_fork`** — copies this host thread. Opening the copy runs `start()`

## deliver vs run

- **`deliver(Envelope $envelope)`** — enqueue on the host inbox and return
- **`run(Envelope $envelope)`** — enqueue and wait until that delivery settles or hits `maxSteps`
- Unsettled open tool calls on resume close with `interrupted` (kind `resumed`)

## Sub-agent storage

Delegated work lives under:

```text
{host.datadir()}/subagents/{leaf}/
```

Transcript files only — no `state.json` for sub-agents.
MD
    ],
    'concepts/tools' => [
        'Tools',
        <<<'MD'
# Tools

A delivery's tool list is assembled by `Switon\Agency\Charters`.

`ToolsInterface` is the registry contract (`expose` / `call`). `Switon\Tooling\Tools` is the implementation.

## Tools

Tool names live on the profile and on catalog segments (`tools:` in frontmatter). Implementations are in-process classes registered by packages.

## MCP

Installing `switon/mcp-client` registers `mcp_servers`, `mcp_tools`, `mcp_schema`, and `mcp_call`. The `mcp` segment adds those names to a profile. Servers come from `~/.switon/mcp.yml` (`Servers::$file`).

`switon/mcp` can serve the same tools (`AbstractTool` / `ToolInterface`) over stdio or streamable HTTP.

## Run loop

`Loop` drives model steps until the delivery settles or hits `maxSteps`.

The catalog model is `Agent::profile()->model`. Pass `model` to `deliver()` / `run()` to override it for that call.
MD
    ],
    'concepts/prompts' => [
        'Prompts',
        <<<'MD'
# System prompts

The runtime renders via `PromptRendererInterface::render(prompt: $agent->profile()->prompt, data: …)`.

Rebuilt **each delivery** — not persisted on the conversation. Host **`state.json`** is a flat **`AgentState::$data`** bag.
Opening the thread reads `profileId` from that bag and re-expands via `ProfilesInterface::get($id)`. A `skills` key on the bag is not the skill advertisement; that is assembled by `Switon\Agency\Charters`.

## Profiles

Registry Markdown (`@agents/*`) → **`Profile`** (`prompt` / `tools`, `skills`, `model`,
`maxSteps`). A desk package qualifies its leaf (`agents/explore.md` under desk `coding` → `coding.explore`). Agents hold one profile via
`Agent::profile()` / `switchProfile($id)`. Create params: `profile` / `profileId`.

## Render inputs

| Arg | Meaning |
|-----|---------|
| `$prompts` | `@alias/...` files (standalone renderer) |
| `$prompt` | Inline body (`Profile::$prompt` at runtime) |
| `$data` | Nested arrays for `{{ }}` / blocks |

Agent render data is built by `Switon\Agency\Charters`.

## Mini language

| Construct | Notes |
|-----------|--------|
| `{{ name }}` / `{{ a.b }}` | Scalar substitute; unknown left literal |
| `{% if path %}` | Truthy PHP `(bool)`; no else |
| `{% if item in list %}` | Left is **literal** name, not data lookup |
| `{% for x in list %}` | Loop; non-array → skip |
| `{% table row in rows %}` | Markdown table header + row template |
| `{# comment #}` | Stripped; non-nesting |

Delimiters `{% %}` keep prompts distinct from Sword `@` views.

## Do not use Sword here

HTML / Sword / `.phtml` belong in `switon/view` + `switon/renderer`. Prompts stay plain text, data-only, safe to log.
MD
    ],
    'concepts/delegation' => [
        'Delegation',
        <<<'MD'
# Delegation

**Agent** (`parent()` null) owns the user thread and minted children. Delegated **Agent** (`parent()` non-null) handles assigned work.

## Flow

1. The root decides to delegate
2. The delegated agent receives inbox `deliver`
3. It runs its own step loop and tools
4. Finish retires the child
MD
    ],
    'reference/quick-api' => [
        'Quick API map',
        <<<'MD'
# Quick API map

| Symbol | Job |
|--------|-----|
| `Switon\Ai\AgentInterface` | Identity, conversation, charter, inbox |
| `Switon\Agency\Agent` | Root or delegated worker; root inputs are `thread`, `profile`, `workspace` |
| `Switon\Tooling\ToolsInterface` | Tool registry contract (`Tools` is `switon/tooling`) |
| `Switon\Agency\LoopInterface` | Step driver |
| `Switon\Agents\PromptRendererInterface` | Prompt templates |
| `Switon\Thread\ThreadsInterface` | Thread facade |
| `Switon\Ai\Charter` | Delivery-stable prompt and tools; assembled by `Switon\Agency\Charters` |

## CLI / eval

- `packages/agency/bin/agent-chat.php`
- Eval: `packages/agency/tests/Eval/run.php` (`skills`, `guessing`)
- CI stub: `bash tests/run.sh agency eval`

## Wire

Live SSE / WebSocket packet shapes: `@switon/agent-thread` `src/packets.ts`

## Canonical docs in repo

- `docs/en/agency/README.md`
- `docs/en/agency/design.md`
- `docs/en/agency/faq.md`
MD
    ],
];

$book = 'switon-agent';
$index = "# Switon Agent\n\n";
foreach ($pages as $rel => [$title, $_body]) {
    $file = str_replace('/', '-', $rel) . '.md';
    $index .= "- [{$title}]({$file})\n";
}
$indexRead = tool($base, $session, $id, 'read', ['book' => $book, 'file' => 'index.md']);
$r = tool($base, $session, $id, 'write', [
    'book' => $book,
    'file' => 'index.md',
    'content' => $index,
    'git_message' => 'Seed index with page links',
    'sha' => $indexRead['sha'] ?? null,
]);
echo "index write sha=" . ($r['sha'] ?? $r['error'] ?? '?') . "\n";

foreach ($pages as $rel => [$title, $body]) {
    $file = str_replace('/', '-', $rel) . '.md';
    $content = page($title, $body);
    $existing = tool($base, $session, $id, 'read', ['book' => $book, 'file' => $file]);
    $args = [
        'book' => $book,
        'file' => $file,
        'content' => $content,
        'git_message' => 'Add ' . $title,
    ];
    if (!isset($existing['error']) && isset($existing['sha'])) {
        $args['sha'] = $existing['sha'];
        $args['git_message'] = 'Update ' . $title;
    }
    $r = tool($base, $session, $id, 'write', $args);
    if (isset($r['error'])) {
        echo "FAIL $file " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }
    echo "OK $file sha={$r['sha']}\n";
}

$tree = tool($base, $session, $id, 'list_files', ['book' => $book]);
echo "files=" . count($tree['entries'] ?? []) . " commit=" . ($tree['commit'] ?? '') . "\n";
echo "DONE\n";
