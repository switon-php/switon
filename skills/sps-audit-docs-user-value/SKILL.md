---
name: sps-audit-docs-user-value
description: Audit component docs from a real-user value perspective by checking first-win speed, hidden prerequisites, example realism, terminology softness, FAQ hit-rate, and recovery usefulness. Use when the goal is not just correctness, but docs users actually like and benefit from.
---

# Docs User-Value Audit (Component-level, Read-only)

## Objective
Evaluate whether `docs/en/{component}` feels genuinely useful from a real user's perspective, not just correct.

This audit complements:
- `sps-audit-docs-drift` for correctness vs implementation
- `sps-audit-docs-snr` for signal vs noise
- `sps-audit-docs-ux` for onboarding and recoverability

Use this skill when the question is closer to:
- "Will users like this doc?"
- "Does this doc feel helpful or just technically correct?"
- "Is this written from the user's problem, not the author's mind?"

## Scope
- Primary docs: `docs/en/{component}/README.md`, `faq.md`, `design.md`
- Code verification: only when needed to confirm whether a missing explanation is already covered elsewhere or whether a recommended path is real
- Read-only audit. Do not modify files.

## Required Pre-reads
1. `standards/README.md`
2. `standards/DOCS_STANDARDS.md`

## The 6 Iron Laws
Judge the docs against these six rules:

1. **First screen must earn trust fast**
   - Within the first screen, users should understand what this component solves, when to use it, and what the easiest successful path is.

2. **No hidden prerequisites on the happy path**
   - If the first useful example depends on prior setup, required bindings, transport assumptions, or another component, say so before the example.

3. **Each section must answer a real user question**
   - Prefer user-task framing over author-centric classification. If a section exists mainly because the system has that concept, treat it as suspect.

4. **Examples must feel real, not decorative**
   - Quick starts should use realistic names, realistic dependencies, and a believable success result. Avoid toy examples that demonstrate syntax without demonstrating value.

5. **Terminology should be introduced gently**
   - The first appearance of a specialized term should carry a short, local hint unless the meaning is already obvious from context.

6. **A stuck user must have somewhere useful to go**
   - When the main path can fail or confuse, README/FAQ should provide the next check, not just more concepts.

## Deterministic Steps
1. Read `README.md` top-down and judge the first screen against Iron Laws 1-4.
2. Read `faq.md` and judge whether it answers the likely first-failure questions against Iron Law 6.
3. Read `design.md` and verify it stays in explanation territory instead of leaking required onboarding content.
4. Cross-check uncertain assumptions against source, tests, or discovery commands.
5. Report only material user-value gaps.

## Primary Finding Tags
- `FirstWin`
- `HiddenPrereq`
- `TaskFit`
- `ExampleRealism`
- `TermSoftness`
- `RecoveryPath`

## Evidence Gate
Each hard finding must include:
- severity: `P1` / `P2` / `P3`
- type: one primary tag from above
- docs location: file + line
- code location: file + line or command output when behavior/path reality matters
- impact: why a real user would feel slower, less confident, or less helped
- recommendation with two levels:
  - `minimal patch`
  - `structural refactor`

If code evidence is not needed because the issue is purely user-facing framing, it may still be reported, but keep it in `Improvements` rather than `Bugs`.

## Severity Rubric
- `P1`: likely harms normal adoption or first-run success
- `P2`: meaningfully reduces confidence, clarity, or perceived usefulness
- `P3`: small but noticeable polish gap

## What To Flag
- Quick starts that show mechanism but not user value
- Required assumptions that appear only later in the doc
- Sections that exist for completeness but do not answer a real user decision
- Specialized terms dropped too early without a local hint
- FAQs that miss the obvious first confusion or first failure
- README content that forces users to jump to `design.md` just to complete a basic judgment

## What Not To Flag
- Pure prose taste differences with no user effect
- Requests for longer explanations when the current text is already sufficient
- Missing glossary-like explanations for terms obvious from immediate code/example context
- Architectural depth that is already isolated in `design.md` and not required for first use

## Output Format
### User-Value Gaps
List the material places where the docs are correct but do not yet feel maximally useful or likable to users.

### Why Users Would Feel It
Summarize the likely user emotion or friction: hesitation, slower first win, lower trust, extra jumping, or weak problem/solution fit.

### User-Value Verdict
One sentence: what single change would most improve perceived usefulness.

### Mapping (For triage)
Map the same findings to `Bugs | Inconsistencies | Improvements`.

If no material findings exist, output:
- `User-Value Gaps: none`
- `Why Users Would Feel It: none`
- `User-Value Verdict: no dedicated user-value refactor required now`
- `Mapping (For triage): Bugs: none | Inconsistencies: none | Improvements: optional`
- `Residual risk: <one line about unverified paths, or 'none'>`

## Natural Language Triggers
This skill is a strong fit when the user says things like:
- "Will users like this doc?"
- "Look at the doc from the user's perspective"
- "Is this doc useful?"
- "What still feels missing?"
- "Will this feel pleasant to use?"
- "It should be more than correct; it should make people want to keep reading"

## Stop Conditions
- Stop when README / FAQ / design are reviewed against the six iron laws.
- Stop early if additional claims would be pure taste rather than user-impacting judgment.
