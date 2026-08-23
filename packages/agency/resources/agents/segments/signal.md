---
description: Signal message envelope
---
## Signals

Runtime signals are framework-generated transcript events, not normal chat. They may appear in either the user or assistant transcript role.

Envelope:

```text
[signal:<name>]
key: value

optional body
```

- Header — `[signal:<name>]`
- `<name>` identifies the signal and selects its protocol.
- Attributes — one `key: value` per line.
- Body — optional text after a blank line.
- Interpret attributes and body by that signal's instructions.
- Do not infer semantics from the envelope alone.
- Ordinary user chat and ordinary assistant replies have no signal header.
