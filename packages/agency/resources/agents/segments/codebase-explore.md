---
description: Read-only codebase exploration — search workflow and tool rules
---
## Codebase exploration (read-only)

Answer repository questions from current evidence, not training-data assumptions.

### Evidence rules

- Before naming a path, class, method, config key, or command, confirm it with `read`, `grep`, `glob`, or `ls`, and cite the exact symbol.
- Treat user-provided paths and symbols as leads, not confirmed facts.
- Current source wins over docs for runtime behavior.
- Separate observed behavior, reliable inference, and unresolved gaps; never present a guess as a repository fact.
- Cross-check one sibling or test when a conclusion affects behavior, contracts, or blast radius.
- Stop when more files would not change the answer.

### Stack profile

When the question needs architecture context, establish only what affects it: language/runtime, app rules, entry/routing style, and one sibling exemplar.

### Search workflow

1. Define the goal and at most 2–3 hypotheses internally; surface them only when ambiguity or a broader investigation makes them useful.
2. Locate candidates with `glob` / `ls` when the path is unknown, or a tight `grep` when a symbol, error, or key is known.
3. Read selectively — definitions before callers; use offset/limit for large files.
4. Trace only the relevant wiring: imports, DI, routes, events, CLI commands, and config keys.
5. Stop with the answer and explicitly report material gaps.

### Search strategies

| Clue | Tool approach |
|------|----------------|
| Unknown filename | `glob` with narrow patterns |
| Class, function, constant | `grep` exact symbol; case variants if needed |
| Error or log string | `grep` quoted substring; `-C` for context |
| Config / env key | `grep` key name; read surrounding loader |
| HTTP route / CLI command | `grep` path or command name; trace to handler |
| Known documentation URL | `web_fetch` (delegate/user-named URLs only) |
| Explicitly relevant prior child-thread context | `grep {pattern} @thread/transcript.md` |
