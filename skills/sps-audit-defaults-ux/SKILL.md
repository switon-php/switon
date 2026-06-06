---
name: sps-audit-defaults-ux
description: Audit default-value UX to ensure first-run success, low configuration burden, and predictable behavior with evidence-backed findings.
---

# Defaults UX Audit (Component-level, Read-only)

## Objective
Evaluate whether component defaults are safe, predictable, and optimized for first successful user outcomes.

## Scope
- Config and defaults: `packages/{component}/src/`, provider boot/register, config discovery surfaces
- User docs context: `docs/en/{component}/README.md`, `faq.md`
- Optional verification: component tests and runtime discovery commands
- Read-only audit. Do not modify files.

## Required Pre-reads
1. `standards/README.md`
2. `standards/CODE_STANDARDS.md`
3. `standards/COMPONENT_STANDARDS.md`
4. `standards/DOCS_STANDARDS.md`

## Deterministic Steps
1. Identify user-facing defaults (config keys, provider defaults, implicit fallbacks).
2. Evaluate first-run path with defaults only.
3. Check whether defaults match docs claims and expected user intent.
4. Validate uncertain behavior via tests/commands.
5. Report all material defaults UX findings with evidence.

## UX Dimensions (Primary Tags)
- `FirstRunSuccess`: default path works without hidden setup.
- `Predictability`: default behavior matches user expectation.
- `Safety`: defaults avoid risky/surprising outcomes.
- `ConfigBurden`: defaults reduce required config for common path.
- `OverrideClarity`: users can discover and override defaults easily.

## Evidence Gate (Mandatory)
Each finding must include:
- severity: `P0` / `P1` / `P2` / `P3`
- type: `FirstRunSuccess` / `Predictability` / `Safety` / `ConfigBurden` / `OverrideClarity`
- docs location: file + line
- code location: file + line (or command output proving behavior)
- impact: user-facing cost from current defaults
- recommendation with two levels:
  - `minimal patch`
  - `structural refactor`

If docs or code evidence is missing, downgrade to suggestion.

## Severity Rubric
- `P0`: defaults likely cause unsafe or broken first-run behavior.
- `P1`: defaults frequently push users into wrong or costly paths.
- `P2`: defaults add meaningful setup friction or confusion.
- `P3`: minor default ergonomics gap.

## Conflict Resolution
When docs/tests/source disagree, use precedence:
1. current working-tree source
2. tests proving runtime behavior
3. docs text

## Dedup Rules
- One root cause -> one primary finding.
- Additional instances go under impact scope.

## Finding Volume
Component-level deep audit. Findings are not capped.

## Reporting Format
### Defaults UX Findings
### First-Run Friction Patterns
### Defaults UX Verdict
One sentence: highest-leverage default-policy change.

### Mapping (For triage)
Map findings to `Bugs | Inconsistencies | Improvements`.

If no material findings exist, output:
- `Defaults UX Findings: none`
- `First-Run Friction Patterns: none`
- `Defaults UX Verdict: no structural default-policy refactor required now`
- `Mapping (For triage): Bugs: none | Inconsistencies: none | Improvements: optional`
- `Residual risk: <one line about unverified paths, or 'none'>`

## Verification Hooks
- Run component checks when default behavior is uncertain: `bash tests/run.sh {component}`.
- Run docs checks when docs assertions are in scope.
- State explicitly what was run and not run.

## Stop Conditions
- Stop when all user-facing defaults in scope are reviewed with evidence-backed findings.
- If claims cannot be confirmed after repeated checks, record in `Residual risk`.
