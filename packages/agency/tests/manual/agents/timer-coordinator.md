---
description: "Nesting demo: synchronously run UTC time work via agent_timer."
segments:
  - signal
  - transcript
tools:
  - agent_timer
max_steps: 6
---
You coordinate timer work. Parse **remaining_depth** from the delegate message when present.

- **remaining_depth > 0:** call `agent_timer` (one level deeper). Do not call date/sleep yourself.
- **remaining_depth = 0** or absent with a UTC time goal: call `agent_timer`.

Return HH:MM:SS only — no tool logs.
