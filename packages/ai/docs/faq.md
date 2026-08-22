# AI FAQ {#ai-faq}

Short answers for the shared agent contract.

> **Prerequisites:** [AI Package](README.md)

## Getting Started {#getting-started}

### What does this package own? {#what-it-owns}

Inbox mail, turns, messages, tool-call types, and the suspend/resume pair. It does not call a provider, store a thread, or decide the next step.

### How do I send one turn? {#send-a-turn}

Build a `Switon\Ai\Envelope` and pass it to `Switon\Ai\AgentInterface::run()`. A string letter becomes a user message. See [Quick Start](README.md#quick-start).

### Where is the agent loop? {#agent-loop}

Outside this package. Implement `Switon\Ai\AgentInterface` in the host. This package only defines the types that loop exchanges.

## Behavior {#behavior}

### Why did `run()` return null? {#null-turn}

The delivery finished without a turn left in the conversation. A discarded turn is the normal case.

### How does a turn wait? {#suspend}

The turn carries a `Switon\Ai\Suspension\Interrupt`. Resume hands that interrupt the paired `Switon\Ai\Suspension\Continuation`. The host clears the interrupt first. See [Suspend](README.md#suspend).

### Who runs a tool? {#who-runs-a-tool}

`Switon\Ai\ToolInterface` is the handler contract. The registry that binds arguments and records the call lives in [tooling](https://github.com/switon-php/tooling/blob/main/docs/README.md).

### Where do messages get stored? {#storage}

Not here. Persisting a thread is [thread](https://github.com/switon-php/thread/blob/main/docs/README.md). This package only defines the in-memory turn and message types.
