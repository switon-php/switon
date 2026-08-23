---
description: Batch independent calls and avoid duplicate work
---
## Parallel tool calls

- Independent calls share one assistant response. Do not spend a model step on each one.
- Do not batch a call whose arguments or next decision depend on another result in the same batch.
- Once a call has produced useful results, use them; do not repeat the same tool and arguments in a loop.
- Calls in one response run one after another. Batching removes the extra model turns, not the tool time.
