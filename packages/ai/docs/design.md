# Design Rationale {#design-rationale}

`switon/ai` keeps one wire shape so providers, tools, and the agent loop do not each invent a message format.

## See also {#see-also}

- [AI Package](README.md) — usage and the public contract
- [FAQ](faq.md#ai-faq) — common questions

---

## Core Problem {#core-problem}

Agent code drifts as soon as each layer owns its own message and tool shape.

- A provider client invents its own chat rows.
- A tool runner invents its own call id and result.
- The loop invents a second way to pause for a human or a tool reply.

The framework needs one contract those layers can share without owning each other's jobs.

## Primary Goal {#primary-goal}

`switon/ai` standardizes the wire types first: envelope, turn, messages, and tool calls.

- **Benefit:** a provider, a tool, and a stored thread can describe the same turn.
- **Benefit:** suspend and resume have one pair of types.
- **Cost:** this package does not run the loop, call a model, or write thread files.

## Core Choice {#core-choice}

The package treats one delivery as mail, and one model exchange as a turn.

- **Envelope** is the inbox item: letter, model id, and effort.
- **Turn** holds the landed steps and, when waiting, one interrupt.
- **Interrupt and continuation** are the only suspend/resume pair.
- **ToolInterface** is the handler shape. Dispatch stays in the tool registry.

Callers place the rows. This package does not decide which tool runs next.

## Trade-Off {#trade-off}

The contract stops at types and the host-facing agent methods.

- **Benefit:** providers and storage can change without a new message format.
- **Cost:** there is no built-in loop, catalog, or provider client in this package.
