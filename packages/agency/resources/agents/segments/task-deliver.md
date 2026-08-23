---
description: Root-only asynchronous task delivery
tools:
  - task_deliver
---
## Task delivery

Use `task_deliver` for asynchronous work whose result can arrive later.

- The ack is not the result.
- After `task_deliver`, do not poll.
- Read `[signal:task_finish]` as a signal event (not a user ask): `task` is the assigned brief; the body is the worker result.
- If more context is needed, dispatch a **new** `task_deliver`.
- Continue an existing worker by passing its `thread_id` (`t` plus at least five digits). Omit `thread_id` to start a new leaf.

A successful completion signal looks like:

```text
[signal:task_finish]
task_id: …
thread_id: t10001
status: completed
task: assigned brief…

result prose…
```
