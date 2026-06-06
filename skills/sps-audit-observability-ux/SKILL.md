---
name: sps-audit-observability-ux
description: Audit observability UX for user-facing diagnosis paths by evaluating discoverability, actionability, and troubleshooting speed with evidence-backed findings.
---

# Observability UX Audit (Component-level, Read-only)

## Objective
Evaluate whether users can quickly diagnose and recover from issues using the component's observable signals.

## Scope
- Observable surfaces: events, logs, error boundaries, diagnostics commands, docs troubleshooting paths
- Code and docs: `packages/{component}/src/`, `docs/en/{component}/README.md`, `faq.md`
- Optional verification: component tests and diagnostics commands
- Read-only audit. Do not modify files.

## Required Pre-reads
1. `standards/README.md`
2. `standards/CODE_STANDARDS.md`
3. `standards/COMPONENT_STANDARDS.md`
4. `standards/DOCS_STANDARDS.md`

## Deterministic Steps
1. Identify primary failure/diagnosis scenarios users hit in normal use.
2. Map each scenario to available observable signals.
3. Evaluate whether signals are discoverable, actionable, and documented.
4. Verify uncertain claims via source/tests/commands.
5. Report all material observability UX findings with evidence.

## UX Dimensions (Primary Tags)
- `SignalDiscoverability`: users can find relevant diagnostics quickly.
- `SignalActionability`: signals suggest concrete next actions.
- `TroubleshootingFlow`: docs guide users from symptom to fix efficiently.
- `CorrelationEase`: users can connect events/logs/errors across steps.
- `NoiseControl`: signal quality avoids high-noise/low-value output.

## Evidence Gate (Mandatory)
Each finding must include:
- severity: `P0` / `P1` / `P2` / `P3`
- type: `SignalDiscoverability` / `SignalActionability` / `TroubleshootingFlow` / `CorrelationEase` / `NoiseControl`
- docs location: file + line
- code location: file + line (or command output proving behavior)
- impact: diagnosis time/risk for users
- recommendation with two levels:
  - `minimal patch`
  - `structural refactor`

If docs or code evidence is missing, downgrade to suggestion.

## Severity Rubric
- `P0`: users are effectively blind during critical failures.
- `P1`: high-impact diagnosis delays in common failure modes.
- `P2`: meaningful troubleshooting friction with workaround.
- `P3`: minor observability clarity gap.

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
### Observability UX Findings
### Troubleshooting Path Gaps
### Observability UX Verdict
One sentence: highest-leverage diagnostics improvement.

### Mapping (For triage)
Map findings to `Bugs | Inconsistencies | Improvements`.

If no material findings exist, output:
- `Observability UX Findings: none`
- `Troubleshooting Path Gaps: none`
- `Observability UX Verdict: no structural observability UX refactor required now`
- `Mapping (For triage): Bugs: none | Inconsistencies: none | Improvements: optional`
- `Residual risk: <one line about unverified paths, or 'none'>`

## Verification Hooks
- Run component tests when behavior claims are involved: `bash tests/run.sh {component}`.
- Run docs checks when troubleshooting/docs claims are in scope.
- State explicitly what was run and not run.

## Stop Conditions
- Stop when in-scope diagnosis paths are reviewed with evidence-backed findings.
- If claims cannot be confirmed after repeated checks, record in `Residual risk`.
