# AI Package {#ai-package}

`switon/ai` is the shared wire contract for Switon agents: inbox mail, one turn, messages, and tool calls. Choose it when providers, the tool registry, thread storage, and the agent loop must agree on the same types. This package does not call a model, run a tool, or store a thread.

**Key features:**

- **Inbox mail:** one `Switon\Ai\Envelope` carries a letter, the model id, and the chosen effort.
- **One turn:** `Switon\Ai\Turn` keeps the landed steps, token totals, and an optional suspend reason.
- **Wire messages:** user, developer, system, assistant, and tool messages share one shape.
- **Tool wire:** `Switon\Ai\ToolCall` and `Switon\Ai\ToolResult` are the call and the result. `Switon\Ai\ToolInterface` is the in-process handler contract.
- **Suspend and resume:** a turn waits on `Switon\Ai\Suspension\Interrupt` and continues with the paired `Switon\Ai\Suspension\Continuation`.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/ai
```

The host that implements `Switon\Ai\AgentInterface` lives outside this package. `deliver()` queues one envelope. `run()` queues it and blocks until that delivery finishes.

## Quick Start {#quick-start}

```php
use Switon\Ai\AgentInterface;
use Switon\Ai\Envelope;

/** @var AgentInterface $agent */
$envelope = Envelope::of('hi', 'main');
$agent->deliver($envelope);
$turn = $agent->run($envelope);
```

`Envelope::of()` turns a string into `Switon\Ai\Message\UserMessage`. The second argument is the model id. `run()` returns the `Switon\Ai\Turn` when one remains, or `null` when the turn was dropped.

## Core Concepts {#core-concepts}

### Envelope {#envelope}

An **envelope** is one piece of inbox mail: a `Switon\Ai\Letter`, the model id, and an optional effort. `deliver()` is fire-and-forget. `run()` waits for that same delivery. Both require the inbox worker to be started.

### Turn {#turn}

A **turn** is one conversation step. It holds ordered completions, the model id, token totals, and status. The last completion is the tip. A suspended turn also carries an `Switon\Ai\Suspension\Interrupt`.

### Messages and tool calls {#messages}

User, developer, system, assistant, and tool messages are the transcript rows. A tool call on the wire is `Switon\Ai\ToolCall`; the matching result is `Switon\Ai\ToolResult`. Streaming fragments are `Switon\Ai\StreamDelta`. The registry that binds arguments and dispatches `Switon\Ai\ToolInterface::call()` stays in [tooling](https://github.com/switon-php/tooling/blob/main/docs/README.md).

### Suspend {#suspend}

An **interrupt** is the opaque reason a turn is waiting. It resumes with one paired continuation. The host clears the interrupt and marks the turn running before resume. Typical waits are a tool reply, a host review, or a retry.

## Keep in Mind {#keep-in-mind}

- This package is the contract. Model calls stay in [llm](https://github.com/switon-php/llm/blob/main/docs/README.md). Thread files stay in [thread](https://github.com/switon-php/thread/blob/main/docs/README.md).
- `run()` can return `null` when the turn was discarded.
- A direct `ToolInterface::call()` is not the registry path. The registry wraps the return value.

## Next {#next}

- **To debug the contract boundaries:** [FAQ](faq.md#ai-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
