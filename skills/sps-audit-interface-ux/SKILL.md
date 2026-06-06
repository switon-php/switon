---
name: sps-audit-interface-ux
description: Audit user-facing interface UX by evaluating predictability, defaults, error recoverability, and docs-to-API fit with evidence-backed findings.
---

# Interface UX Audit (Component-level, Read-only)

## Objective
Evaluate whether a component's user-facing interfaces are intuitive, predictable, and recoverable for first-time and repeat users.

## Scope
- API surface: `packages/{component}/src/` (interfaces + public implementations)
- User docs for context: `docs/en/{component}/README.md`, `docs/en/{component}/faq.md`
- Optional supporting evidence: component tests and config discovery paths
- Read-only audit. Do not modify files.

## Required Pre-reads
1. `standards/README.md`
2. `standards/CODE_STANDARDS.md`
3. `standards/COMPONENT_STANDARDS.md`
4. `standards/DOCS_STANDARDS.md`

## Deterministic Steps
1. Identify the public entrypoints users touch first (interface methods, attributes, config keys).
2. Evaluate first-use path from docs to API call shape.
3. Cross-check behavior contracts against source and tests.
4. Validate uncertain claims with commands before concluding.
5. Report all material findings with evidence.

## UX Dimensions (Primary Tags)
- `Predictability`: mode switches, hidden branches, non-obvious defaults.
- `Learnability`: first successful usage path complexity.
- `Recoverability`: actionable failure and retry paths.
- `ConfigBurden`: required config volume and caveat density.
- `DocsToApiFit`: explanation cost vs API surface simplicity.

## Evidence Gate (Mandatory)
Each finding must include:
- severity: `P0` / `P1` / `P2` / `P3`
- type: `Predictability` / `Learnability` / `Recoverability` / `ConfigBurden` / `DocsToApiFit`
- docs location: file + line
- code location: file + line (or command output proving behavior)
- impact: user-facing friction or failure risk
- recommendation with two levels:
  - `minimal patch`
  - `structural refactor`

If docs or code evidence is missing, downgrade to suggestion.

## Severity Rubric
- `P0`: likely widespread misuse, unsafe integration, or severe breakage risk.
- `P1`: high-frequency confusion or costly misuse in normal adoption.
- `P2`: clear friction with practical but non-obvious workaround.
- `P3`: minor ergonomics issue with low operational risk.

## Conflict Resolution
When docs/tests/source disagree, use precedence:
1. current working-tree source
2. tests proving runtime behavior
3. docs text

## Dedup Rules
- One root cause -> one primary finding.
- Additional instances go under impact scope, not duplicate findings.

## Finding Volume
Component-level deep audit. Findings are not capped.

## Reporting Format
### Interface UX Findings
### Risky Interaction Patterns
### Refactor Verdict
One sentence: highest-leverage interface change for UX improvement.

### Mapping (For triage)
Map findings to `Bugs | Inconsistencies | Improvements`.

If no material findings exist, output:
- `Interface UX Findings: none`
- `Risky Interaction Patterns: none`
- `Refactor Verdict: no structural interface refactor required now`
- `Mapping (For triage): Bugs: none | Inconsistencies: none | Improvements: optional`
- `Residual risk: <one line about unverified paths, or 'none'>`

## Verification Hooks
- Prefer: `bash tests/run.sh {component}` when behavior uncertainty exists.
- For docs references/anchors in scope, run repo docs checks.
- State explicitly what was run and not run.

## Stop Conditions
- Stop when public entrypoints in scope are reviewed with evidence-backed findings.
- If claims cannot be confirmed after repeated checks, record in `Residual risk`.
