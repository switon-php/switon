---
name: sps-audit-docs-drift
description: Read-only docs drift audit for docs/en/{component} against packages/{component} with standards-first checks and evidence-based findings.
---

# Docs Drift Audit (Read-only)

## Objective
Identify docs drift between `docs/en/{component}/` and `packages/{component}/` that can mislead integration or violate component boundaries.

## Scope
- Primary docs: `docs/en/{component}/README.md`
- Supporting docs when needed: `docs/en/{component}/faq.md`, `docs/en/{component}/design.md`
- Code surface: `packages/{component}/src/` (plus tests/config/discovery commands when required to verify behavior)
- This skill is analysis-only. Do not modify files.

## Input Contract
When invoked, normalize input to:
- `component`: package name (for example `view`)
- `doc`: `README` / `faq` / `design` / `all` (default: `README`)
- `scope`: `staged` / `today` / `all` / explicit file list (default: `staged`)
- `strictness`: `normal` / `high` (default: `normal`)

## Required Pre-reads
Before auditing, read:
1. `standards/README.md`
2. `standards/DOCS_STANDARDS.md`
3. `skills/sps-verify-identifiers/SKILL.md`

## 5-Step Execution Checklist
Use this fixed sequence to keep results stable across agents:
1. Locate change scope (`staged` / `today` / explicit files).
2. Read target docs (`README.md` first, then `faq.md` / `design.md` if needed).
3. Run the `sps-verify-identifiers` workflow on all machine-meaningful identifiers in scope.
4. Cross-check against code contracts in `packages/{component}/src/` (and tests/config if needed).
5. Run only necessary verification commands (links/headings/discovery/grep) to confirm uncertain points.
6. Report findings in the required output structure.

## Drift Rules (IF/THEN)
- IF `scope=staged`, THEN audit only files from `git diff --name-only -- <target>`.
- IF `scope=today`, THEN audit files changed since local 00:00 today (`git log --since ... --name-only` + working tree diff).
- IF `scope=all`, THEN audit full `docs/en/{component}/`.
- IF a claim cannot be tied to code evidence, THEN do not report it as `Bugs` or `Inconsistencies`.
- IF evidence has docs line but no code line, THEN downgrade to `Suggested Improvements`.
- IF EN headings/anchors changed and locale files were not requested, THEN add one locale-sync note only (do not fail).
- IF a token is a namespace-family shorthand such as `Switon\Foo\Bar\*`, THEN do not report it as missing API drift merely because no exact class matches that literal text.
- IF a token is an explicit placeholder or anchor such as `<id>`, `{field}`, or `{#quick-start}`, THEN do not report it as API drift; only check whether its surrounding explanation is clear.

### Drift Classification Rules
- IF docs symbol/signature/type differs from code contract, THEN classify as `API` drift.
- IF docs config key/default/discovery entry differs from runtime source, THEN classify as `Config` drift.
- IF docs runtime flow/order/merge/event semantics differ from implementation, THEN classify as `Behavior` drift.
- IF docs example cannot run as written (missing import/wrong attribute/wrong call shape), THEN classify as `Example` drift.
- IF docs assign responsibility to the wrong layer/package, THEN classify as `Boundary` drift.
- IF docs anchor/link/route target is wrong or stale, THEN classify as `Link` drift.

## Drift Type Tags
Tag each finding with one primary type:
- `API` (symbol/signature/types)
- `Config` (keys/defaults/discovery)
- `Behavior` (runtime logic/merge/order/flow)
- `Example` (snippet runnable correctness)
- `Boundary` (ownership/layer responsibilities)
- `Link` (anchors/routes/reference correctness)

## What To Verify
- API contracts: class/interface names, method signatures, parameter/return types
- Config contracts: option keys, defaults, and discovery commands
- Runtime behavior: mapping rules, merge order, event flow, boundary ownership
- Example viability: snippet symbols and imports are real and consistent with code
- Architecture fit: docs keep View/Renderer/HTTP boundaries clear and non-hallucinated
- Naming fit: identifiers and DI-facing property names stay semantically aligned with Switon conventions

## Anti-Patterns (Do Not Report)
- Superficial string drift: exact error text, table formatting, log wording, cosmetic phrasing
- Noise-only wording preferences without behavioral impact
- Unverified guesses; every finding must have code evidence
- Namespace-family shorthand or template placeholders reported as if they were failed exact symbol lookups

## Evidence Rules
- Each finding must include:
  - severity: `P0` / `P1` / `P2` / `P3`
  - type: `API` / `Config` / `Behavior` / `Example` / `Boundary` / `Link`
  - docs location: file + line
  - code location: file + line (or command output proving current behavior)
  - impact: what can break or mislead
  - fix direction: concise, actionable suggestion
- Prefer line-precise references over broad sections.
- Missing evidence policy:
  - missing `docs location` or `code location` => cannot be `Bugs`/`Inconsistencies`
  - place it under `Suggested Improvements` with reason `insufficient evidence`

## Severity Rubric
- `P0`: likely crash/data loss/security-risk due to wrong doc contract.
- `P1`: high-impact behavioral drift that can cause broken integration.
- `P2`: meaningful mismatch/confusion with moderate user impact.
- `P3`: minor clarity or consistency issue with low risk.

## Output Format
Use this exact top-level structure:

### Bugs
Contract/behavior drift with user impact. Order by severity.

### Inconsistencies
Cross-doc or docs-vs-code inconsistencies that may not crash but can confuse.

### Suggested Improvements
Low-risk clarity improvements after bugs/inconsistencies.

For each item in `Suggested Improvements`, add priority:
- `Now`: do immediately
- `Next`: do in normal follow-up
- `Later`: optional / low urgency

## Locale Sync Hint (Optional)
If English headings/anchors changed, add one note under `Inconsistencies`:
- locale sync may be needed in `docs/{locale}/{component}/...`
- do not fail audit on locale parity unless explicitly requested

If no findings exist, output exactly:
- `Bugs: none`
- `Inconsistencies: none`
- `Suggested Improvements: <optional with Now/Next/Later>`
- `Residual risk: <one line about unverified paths, or 'none'>`

## Stop Conditions
- Stop when all in-scope changed docs are checked and uncertain claims are verified or downgraded.
- Stop early if repeated attempts cannot produce code evidence; record in `Residual risk`.
