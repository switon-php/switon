# Async delegation {#async-delegation}

**Audience:** orchestrator LLM (tool choice). **Status:** proposal — today `agent_exec` / `agent_send` block until the delegate finishes; `agent_kill` is unreachable while the parent is blocked.

**Terminology:** Domain stack is **thread → turn → run → step** ([WIRE.md](WIRE.md)). In this doc, JSON field **`turn`** is a **per-delegation-session serial counter** (business message index), **not** layer-2 Turn (UI timeline slice) and **not** layer-3 agent run (`runId`). Prefer reading it as `session_turn` when implementing.

**Why:** sync vs async is chosen at call time. Same semantics either way; sync stays the default debug path; without Swoole async degrades safely.

## One rule {#one-rule}

**One agent = one business turn at a time.** Parallel work = more sub-agents, never concurrent turns on one session.

- `turn` is a per-session version stamp (serial stream), **not** a routing id.
- Coalescing uses an **LLM-chosen `group` label**, not returned `session_id` handles — ids are server-generated and unknown in the same assistant message.
- `agent_kill` and `agent_join` are control-plane; they do not consume a business turn.

## Choose a mode {#choose-a-mode}

| Need | Do this |
|------|---------|
| Need this result now | `wait:true` (default) — returns `content` inline |
| Kick off work, keep going | `wait:false` — returns handle; content arrives later |
| N background tasks, act once all done | Same message: N× `wait:false` + `join:"g1"`, then `agent_join(group:"g1")` |
| Deterministic result order | Use `group` (dispatch order, not completion order) |
| Only some tasks must arrive together | Tag that subset with `group`; leave others untagged (they drip individually) |
| Parallel follow-ups on warm context | `thread_fork` → N sessions → `agent_send` each (`wait:false` + optional `group`) |
| Stop a runaway task | `agent_kill` (cascade; not instant) |
| Multi-turn dialogue on one session | `agent_create` → sequential `agent_send` (wait for each result before the next) |

**No blocking multi-collect.** `wait:true` blocks for one sub-call only. There is no `agent_wait`.

## Tools {#tools}

| Tool | Args | Returns / errors |
|------|------|------------------|
| `agent_exec` | `expert`, `message`, `wait?=true`, `join?` | `wait:true` → `{session_id, turn, content}` · `wait:false` → `{session_id, turn, status:"running"}` |
| `agent_send` | `session_id`, `message`, `wait?=true`, `join?` | Same as above · **errors if session is busy** (never queues) |
| `agent_join` | `group` | Non-blocking; closes group for coalesced delivery · unknown/untagged group → **immediate error** · returns `{status:"joined", group, coalescing:[…]}` |
| `agent_kill` | `session_id` | Cascade cancel; always reachable (parent only blocks on `wait:true`) |
| `agent_list` | — | Each session: `status` (`idle`/`running`/`cancelled`/`failed`/`completed`), `turn` |

Only `wait:true` blocks the parent — so `agent_kill` stays reachable between background dispatches.

## Fan-out pattern {#fan-out-pattern}

**Must be one assistant message** — `group` is chosen before dispatch; `refs` over `session_id` cannot work same-turn (ids do not exist yet at generation time).

```
agent_exec(expert=a, wait:false, join:"g1")
agent_exec(expert=b, wait:false, join:"g1")
agent_exec(expert=c, wait:false, join:"g1")
agent_join(group:"g1")
```

1. Each tagged dispatch registers under `g1`; results are **held**, not dripped.
2. `agent_join` **closes** `g1` (no more members).
3. When all members finish, **one** delivery arrives (dispatch order).

Forgot `agent_join`? Tagged results drip individually at next drain — extra tokens, not a hang.

Untagged `wait:false` results drip one parent turn each. Under Swoole, drip order is completion order (nondeterministic) — use `group` when order matters.

## Session rules {#session-rules}

- **Busy:** from background dispatch until the agent run completes and delivers. Second business dispatch to same session → `{error: "session … is busy"}` — wait for delivery or spawn another sub-agent.
- **Three things at once:** three sub-agents (concurrency gated, default 4/agent; compounds recursively).
- Check `agent_list` before `agent_send` on a persistent session.

## Result delivery {#result-delivery}

Background results surface on a **later parent turn** as a `UserMessage` envelope (default):

```
<delegate_result session_id="…" turn="2" expert="coding.explore" description="…" status="completed">
…content…
</delegate_result>
```

Coalesced group → one message with N envelopes (dispatch order). Apps may override formatting via `DelegateMessageInterface` in `switon.yml`.

## Runtime {#runtime}

| | Swoole | No Swoole |
|---|--------|-----------|
| `wait:true` | block sub-call, return `content` | **identical** |
| `wait:false` | coroutine; real concurrency | runs eagerly now; **no concurrency gain**; result delayed one turn |
| `agent_join` | coalesce delivery once all done | coalesce **delivery** only (members already ran) |
| `agent_kill` | abort at next step/tool boundary; cascade | same boundary semantics |

**No Swoole:** prefer `wait:true` for a single task. Use `wait:false` + `group` only to merge several deliveries into one turn — not to save wall-clock time.

## Cancellation {#cancellation}

- **Not instant** — abort at next step/tool-call boundary (long tool calls finish first).
- **Cascade** — kill propagates to in-flight child delegations.
- Idle session killed → marked `cancelled`, torn down; use `agent_create` / fresh `agent_exec` to reuse.

## Failure modes (safe) {#failure-modes}

| Mistake | Outcome |
|---------|---------|
| `agent_send` to busy session | Error — no transcript race |
| `agent_join` on unknown group | Error — no hang |
| Forgot `agent_join` | Drip individually — costly, correct |
| Skip coalescing | Extra parent turns — state stays correct |

Dispatch that fails **before** spawning returns error and **no** handle (do not add to a group).

---

## Implementation appendix {#implementation-appendix}

*For maintainers — not required for tool choice.*

### Delivery stack

- **`DelegateInboxInterface`** — registry-side mailbox: `deliver`, `register(group)`, `join` (close), `drain`.
- **`DelegateMessageInterface`** — `toMessage(list<DelegateResult>)`.
- **`DelegateResult`** — `{sessionId, turn, group?, expert, description, status, content, error?}`.
- **`Loop`** drains inbox at step boundary and `run()` start; converted messages append **after** that turn's `ToolResult`s (tool pairing invariant).

### Registry state (`Delegations`)

Per parent: `session_id ⇒ {busy, lastStatus, coroutine?, groups}`. Not rebuilt by `Delegations::get()`. `turn` persisted with conversation snapshot.

`Delegations::dispatch(sessionId, message, wait, group?)` — sets busy, assigns turn, `register()` before yield when grouped.

### Primitives reused

- `Runtime::isCoroutineEnabled()`, `Swoole\Coroutine::create`, `Swoole\Coroutine\Channel`
- `RunRequest::cancel()` + `Loop` step-boundary check
- Per-session file isolation (siblings never race transcript)

### Landing map (`packages/agency`)

`AgentInterface::inbox()` · default inbox + message impls · `DelegateResult` · registry async state on `Delegations` · inbox drain in `Loop` · cascade cancel + idle-session fix · `AgentExecTool` / `AgentSendTool` (`wait`, `join`) · `AgentJoinTool` · `AgentListTool` status/turn · concurrency gate (default 4/agent).

### Phase 2 (TUI only)

Multiplex `readline` + inbox wakeups so a live prompt reacts when a background result lands — no inbox/converter API change.

### Deferred / sharp edges

- Cross-process durable background (v1 in-memory; process death loses undrained inbox).
- Global vs per-agent concurrency cap.
- Recursive fan-out: 4×4 = 16 in flight two levels deep (v1 accepts; global gate if needed).
- Deliver-exactly-once: every spawned turn must `deliver()` one result (`completed`/`failed`/`cancelled`).

### Design trade-offs

- `group` is an opaque batch tag — not routing; enables same-message coalescing that `refs` cannot.
- Inbox vs converter split: hold/coalesce vs format independently swappable.
- Deletability: drop async → remove `wait:false`/`join`, drain, `agent_join`, cascade, gate, registry map; sync path unchanged.
