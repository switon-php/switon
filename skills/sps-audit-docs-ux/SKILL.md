---
name: sps-audit-docs-ux
description: Audit docs UX for user-facing component guides by checking first-run clarity, decision flow, and troubleshooting recoverability with evidence-backed findings.
---

# Docs UX Audit (Component-level, Read-only)

## Objective
Evaluate whether `docs/en/{component}` makes users succeed fast with low confusion and clear next steps.

## Scope
- Primary docs: `docs/en/{component}/README.md`, `faq.md`, `design.md`
- Code verification: `packages/{component}/src/` and relevant tests/commands
- Read-only audit. Do not modify files.

## Required Pre-reads
1. `standards/README.md`
2. `standards/DOCS_STANDARDS.md`

## Deterministic Steps
1. Evaluate first screen for fit, value, boundaries, and success path clarity.
2. Evaluate quickstart and first integration flow for minimum cognitive load.
3. Evaluate decision points and troubleshooting for recoverability.
4. Verify code-like identifiers, behaviors, and commands against source.
5. Report all material UX friction with evidence.

## UX Dimensions (Primary Tags)
- `FirstRunClarity`: user can start without hidden prerequisites.
- `DecisionFlow`: users can choose the right path quickly.
- `CognitiveLoad`: caveat density and concept switching burden.
- `Recoverability`: troubleshooting entries provide actionable next steps.
- `TrustSignal`: examples/commands/symbols are verifiably correct.

## `First 10 minutes` Guardrail (Mandatory Check)
- `First 10 minutes` is optional. Report as noise when it exists without clear onboarding-risk trigger.
- Valid trigger signals include: repeated first-run confusion, high-frequency shape decisions, or normal-flow deny/misconfiguration recovery burden.
- If present, enforce constraints:
  - max 3 ordered steps
  - roughly 6-10 lines total
  - each step uses `do -> expected result -> if failed, check`
  - no duplication of Quick Start or FAQ content
- Violation mapping:
  - no trigger but section exists -> `Improvements` (remove or fold)
  - over-length or duplicated content -> `Inconsistencies` (trim/route)

## Evidence Gate (Mandatory)
Each finding must include:
- severity: `P0` / `P1` / `P2` / `P3`
- type: `FirstRunClarity` / `DecisionFlow` / `CognitiveLoad` / `Recoverability` / `TrustSignal`
- docs location: file + line
- code location: file + line (or command output proving behavior)
- impact: user confusion, wrong path, or adoption slowdown
- recommendation with two levels:
  - `minimal patch`
  - `structural refactor`

If docs or code evidence is missing, downgrade to suggestion.

## Severity Rubric
- `P0`: docs likely drives users into broken/unsafe integration.
- `P1`: high-impact path confusion in normal onboarding.
- `P2`: meaningful friction requiring trial-and-error.
- `P3`: low-risk clarity gap.

## Command Hints
- scope discovery: `git diff --name-only`, `git log --since ... --name-only`
- lookup/verification: `rg -n`, `nl -ba`, `sed -n`
- behavior checks: `bash tests/run.sh {component}`
- docs sanity: repo docs check scripts

## Conflict Resolution
When docs/tests/source disagree, use precedence:
1. current working-tree source
2. tests proving runtime behavior
3. docs text

## Dedup Rules
- One root cause -> one primary finding.
- Additional occurrences go under impact scope.

## Finding Volume
Component-level deep audit. Findings are not capped.

## Reporting Format
### Onboarding UX Friction
### Decision & Troubleshooting Gaps
### Docs UX Verdict
One sentence: most leveraged docs structure/content change.

### Mapping (For triage)
Map findings to `Bugs | Inconsistencies | Improvements`.

If no material findings exist, output:
- `Onboarding UX Friction: none`
- `Decision & Troubleshooting Gaps: none`
- `Docs UX Verdict: no structural docs UX refactor required now`
- `Mapping (For triage): Bugs: none | Inconsistencies: none | Improvements: optional`
- `Residual risk: <one line about unverified paths, or 'none'>`

## Verification Hooks
- Run docs checks when docs changed.
- Run component tests when behavior claims are involved.
- State explicitly what was run and not run.

## Stop Conditions
- Stop when in-scope docs paths are reviewed with evidence-backed findings.
- If claims cannot be confirmed after repeated checks, record in `Residual risk`.
