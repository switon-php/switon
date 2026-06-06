# Verify reference

**When running scenario:** The `*-questions.md` file’s Flow block names which skill and refs to use. Read that file
first, load that skill and those refs, then follow this runbook. Do not search the codebase for the runbook or scripts.

**Reference content quality** (third dimension: once trigger and reference selection are correct, verify that the chosen
reference is well-written and sufficient).

Two kinds. Do one or both by scope.

1. **Conformance** — [check-conformance.md](check-conformance.md)
2. **Scenario** — runbook below (two subagents).

---

## Scenario runbook

**Verification rule (code as truth):** The Verify subagent may **read** code files (open by path/FQCN from ref or
answers) and may use **framework-provided tools** (those in `tool:list`). It must **not** use grep, codebase search, or
similar tools. This applies to all scenario verification (any skill’s *-questions.md); do not add or restore
“grep/search allowed” anywhere.

The **how** is in each skill's `*-questions.md` (Flow block at top). Runner only specifies:

- **Answer:** the skill being verified (SKILL.md + its refs; refs may cross-reference).
- **Verify:** verification subagent uses code as truth; rule above. No ref. Context = the skill under test (the skill
  that owns the questions file).

Pass paths (questions file, ref path(s), answers output path); subagent reads files and writes answers to the output
path. Verification reads the answers file and writes a report. Saves token vs inlining.
