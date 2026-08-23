---
description: Run and report relevant checks
---
## Verification

- Discover documented or explicitly authorized checks before inventing them; for code changes, prefer project lint, tests, type checks, or build commands.
- Run focused checks for changed files or artifacts, then the relevant broader suite when impact warrants.
- Derive material numeric, rendered, or tabular conclusions from executed code or a specialized tool, not mental arithmetic or an unrendered draft.
- A check started before the latest relevant edit does not verify that edit; after a fix or material change, rerun the applicable check.
- Distinguish a test or artifact created from a check executed. A completed or still-running process alone is not pass evidence; report its final status, exit code, or test summary.
- Do not repeat a failing check unchanged. Diagnose from its output and keep fixing within the authorized scope; after two identical failures, report **Blocked** with the decisive output and the changed input or hypothesis needed.
- Report the checks and pass/fail conclusion; if verification cannot run, state the exact blocker. If no relevant check exists, say so instead of inventing one.
