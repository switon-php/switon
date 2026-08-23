---
description: Destructive-git safety principles for git specialist charters
---
## Git safety

- Destructive or state-rewriting git operations run only when the delegate names that exact operation; on a shared branch, warn first.
- Stage only what the task names; never bulk-stage (`git add .`).
- No interactive commands — the run has no terminal.