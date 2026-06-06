---
name: sps-audit-component-quality
description: Reverse-engineer component quality and API design smells purely by evaluating how difficult it is to document them.
---

# Docs-Driven Component Quality Audit (Objective-Driven)

**Core Objective**: Use documentation cognitive load and "explanation friction" to diagnose underlying flaws in the component's API design, architecture, and Developer Experience (DX).

*Premise: If a feature requires exhausting explanations, complex setup steps, or endless caveats, the documentation isn't the problem—the API is.*

## Your Mission
You are a Staff Engineer evaluating framework code quality through the lens of its documentation. Read the component's `docs/en/{component}/README.md` and `faq.md`. 

**Do not critique the writer's grammar or formatting.** Your goal is to feel the "cognitive weight" of the component and trace it back to the codebase:
- Does the Quickstart feel too heavy? (Diagnose: missing defaults, poor Factory design).
- Are there too many caveats and conditional flags? (Diagnose: God classes, SRP violations).
- Are internal mechanisms over-explained just to use a public method? (Diagnose: Leaky abstractions).
- Is the terminology confusing? (Diagnose: Poor domain-driven design).

## How to execute
Use your own judgment, vast knowledge of architectural anti-patterns, and empathy for the end-user. If reading the docs makes you think "this is overly complicated to use," identify exactly what code refactoring would make the *document* simpler to write.

## Language-Fit Guardrails
- Judge the API against the idioms of the target language/runtime first, not against Java/C#/Rust-style type aesthetics.
- In PHP, a lightweight DSL-like input slot may legitimately accept strings, `?`-prefixed variants, callables, `null`, or other compact scalar forms without being a design smell by default.
- Do **not** recommend wrapper objects, typed helper classes, marker interfaces, or extra contracts merely because an input is flexible or documented with several accepted shapes.
- "Needs explanation" alone is **not** enough to call the API flawed. It becomes a real finding only when the flexibility causes one of these:
  - repeated misuse in normal call sites,
  - ambiguous or unreadable call sites,
  - invalid states that tests/docs must repeatedly defend against,
  - setup ceremony that is higher than the language normally warrants,
  - branching behavior that users cannot predict from the call shape.
- Prefer reporting a language-fit API as **acceptable** when it is concise, idiomatic, well-tested, and documentable, even if it is not maximally type-explicit.
- Treat "could be more typed" and "should be refactored" as different judgments. Only report the latter with concrete DX or behavior evidence.

## Deterministic Steps
Run this sequence in order to keep output stable across agents:
1. Locate audit scope (changed files / today / full component docs).
2. Read `docs/en/{component}/README.md` and `faq.md` fully.
3. Run a language-fit check: ask whether the "friction" is true user burden or simply an idiomatic API shape for this language/runtime.
4. Cross-check friction points against `packages/{component}/src/` (and tests/config when needed).
5. Validate uncertain claims with targeted commands before reporting.
6. Report findings using the required sections and evidence gate.

## Drift Type Tags
Tag each finding with one primary type:
- `API` (public interface shape/signature ergonomics)
- `Config` (defaults, keys, discoverability, option surface)
- `Behavior` (runtime mode switches, merge order, side effects)
- `Boundary` (layer ownership, leaky abstraction, cross-component coupling)
- `Naming` (terminology ambiguity harming DX)
- `DX` (setup burden, caveat density, onboarding friction)

## Command Hints
Prefer fast, deterministic commands:
- scope discovery: `git diff --name-only`, `git log --since ... --name-only`
- code/doc lookup: `rg -n`, `rg --files`, `sed -n`, `nl -ba`
- component verification: `bash tests/run.sh {component}`
- link/header sanity (when docs changed): existing repo check scripts

## Conflict Resolution
- When docs, tests, and source disagree, use this precedence:
  1. current source in working tree
  2. component tests that prove runtime behavior
  3. docs text
- Report the conflict explicitly with both evidence points.

## Evidence Freshness
- Use evidence from the current working tree (including uncommitted changes) by default.
- Do not rely on stale historical assumptions when current files differ.

## Evidence Gate (Mandatory)
- Every finding must include:
  - severity: `P0` / `P1` / `P2` / `P3`
  - type: `API` / `Config` / `Behavior` / `Boundary` / `Naming` / `DX`
  - docs location: file + line
  - code location: file + line (or command output proving behavior)
  - impact: why this increases DX/documentation friction
  - refactor direction: what to change in API/architecture
- If either docs or code evidence is missing, do **not** classify as a hard finding; downgrade to a suggestion.

## Dedup Rules
- One root cause -> one primary finding.
- Additional occurrences should be listed as impact scope, not separate duplicate findings.

## False-Positive Filters
Do **not** report the following as hard findings unless there is additional evidence of real misuse, ambiguity, or instability:
- flexible `mixed` / scalar / callable inputs that are idiomatic for PHP and keep common call sites short,
- string-based mini-DSLs that are stable, documented, and covered by tests,
- "this could be an object/interface" arguments based only on type purity,
- recommendations that mainly move a PHP API toward Java-style ceremony rather than solving a proven DX problem.

## Severity Rubric
- `P0`: critical design flaw likely causing widespread misuse, breakage, or unsafe integration paths.
- `P1`: high-impact API/architecture friction that repeatedly causes confusion or heavy caveats.
- `P2`: meaningful complexity/leakage that raises cognitive load but has workable mitigation.
- `P3`: minor naming/ergonomics inconsistency with limited practical impact.

## Finding Volume
- This is a component-level deep audit.
- Findings are **not capped** by count; report all material findings with evidence.

## Reporting
**CRITICAL: DO NOT modify any files.** This is a strict read-only audit.

Report your findings back formatted EXACTLY like this:

### DX Friction & Setup Pain
*Is the API too verbose? Does it lack sensible defaults? How high is the barrier to entry?*

### Architecture & Abstraction Smells
*Are abstractions leaking? Are there signs of 'God Classes', confusing naming, or parameter explosions?*

### Refactoring Verdict
*One sentence: What specific API or architectural refactor would make this component dramatically easier to document and use?*

### Mapping (For triage)
*Map the same findings to: `Bugs | Inconsistencies | Improvements` for downstream workflow compatibility.*

For each recommendation, provide two granularity options:
- `minimal patch`: smallest safe change for immediate improvement
- `structural refactor`: deeper change for long-term simplification

If no material findings exist, output:
- `DX Friction & Setup Pain: none`
- `Architecture & Abstraction Smells: none`
- `Refactoring Verdict: no structural refactor required now`
- `Mapping (For triage): Bugs: none | Inconsistencies: none | Improvements: optional`
- `Residual risk: <one line about unverified areas, or 'none'>`

## Verification Hooks
- Before final report, run available minimal checks for touched scope:
  - component behavior: `bash tests/run.sh {component}` (when feasible)
  - docs sanity: repo docs checks when docs are in scope
- In the report footer, state what was run and what was not run.

## Stop Conditions
- Stop when in-scope docs are fully reviewed, findings are evidence-backed, and duplicates are merged.
- If repeated attempts cannot confirm a claim with fresh evidence, stop and record it in `Residual risk`.
