---
name: sps-dual-doc-review
description: Documentation review for switondown docs. Use for dual-pass documentation reviews, check+review audits, review-before-edit workflows, or package-by-package doc review with `next`. Run the audit serially in the main agent; do not delegate parallel subagents.
---

# Serial Doc Review

## When to Use

- Run two-pass audits on framework Markdown docs.
- Keep review-only mode before edits.
- Continue package-by-package review with `next`.
- Audit a concrete doc, component doc set, or active review queue.

## Canonical Rules

- Read `standards/README.md` first.
- For Markdown rules, follow `standards/DOCS_STANDARDS.md`.
- Inherit `sps-docs` for doc routing and default doc-shape baseline:
  - explicit reference doc wins
  - otherwise use the same doc type in `docs/en/log/`
  - if baseline and `DOCS_STANDARDS.md` conflict, `DOCS_STANDARDS.md` wins
- Use evidence priority for factual judgments:
  - code or authoritative discovery command
  - `standards/DOCS_STANDARDS.md`
  - sibling docs or current house-style examples
  - inference
- Treat `docs/en/**` as package-facing published docs, not monorepo contributor docs.
- Prefer boundaries, checklists, and output shape over persona wording.

## Default Mode

- Review only by default. Do not edit during audit.
- Do not modify files until the user explicitly asks to fix or confirms the findings batch.
- Run `check`, then `review`, then merge in the main agent.
- Do **not** spawn parallel subagents for the audit by default. Keep the reasoning, evidence collection, and deduplication in one place unless the user explicitly asks for delegated work.
- Use default traversal only when no concrete path is provided:
  - `docs/en/*` lexical order
  - prefer each target's primary entry doc, usually `docs/en/{target}/README.md`
- Support `next` to continue the active review queue.
- If a doc has no high-confidence findings, report only `no issues` plus `current`, `next`, and `remaining`, then wait for the next user instruction.

## Selection and Continuation Protocol

When the user request is ambiguous, ask one short numbered list and allow comma-separated replies such as `1,3,5`.

Recommended menu:

1. review current target only
2. review and wait for fix decision
3. review and auto-fix low-risk doc issues
4. `next`
5. unattended `next` until blocked

If the user already gave a clear mode, skip the menu.
If the user says `next`, continue in `docs/en/*` lexical order.
If the user says `unattended`, keep advancing until a blocker or explicit stop.

## Stage-Gate Policy (Mandatory)

- Do **not** auto-advance stages.
- After each stage, report results and ask for user confirmation before moving to the next stage.
- If the user explicitly authorizes continuous execution, still emit stage checkpoints and keep pause-on-request behavior.

Recommended stages:
1. **Stage 1 - Audit:** run `check`, then `review`, then merge and deduplicate findings.
2. **Stage 2 - Decision:** wait for user decision (`A/B/C` or direct edit instruction).
3. **Stage 3 - Fix:** apply only approved scope fixes.
4. **Stage 4 - Verify:** run lints/link checks and report residual risk.

### Stage Exit Criteria (Pass/Fail)

- **Stage 1 pass:** merged findings are evidence-backed and deduplicated.
- **Stage 2 pass:** user confirmation is explicit.
- **Stage 3 pass:** edits are confined to approved scope and findings are resolved.
- **Stage 4 pass:** verification checks pass and no new high-confidence issues are introduced.

If a stage fails, remediate within the same stage and re-check before requesting stage confirmation.

## Role Boundaries

The main agent should keep the two passes logically separate, then merge after deduplication.
For mixed issues, assign ownership by primary risk:
- navigation, anchor, contents, and section-order failures belong to `check`
- factual, behavioral, prerequisite, and reader-misleading failures belong to `review`

### `check` — standards / structure reviewer

**Output bias:** “Does this conform to repo doc rules and current house style?”

**In scope:**

- Heading levels, `{#anchor}` coverage on linkable headings, and section spine vs `standards/DOCS_STANDARDS.md`
- Contents style, including the default compact inline `·` navigation
- Links: targets exist, relative paths are correct, and `faq.md` / `design.md` route to document entry when required
- Terminology and formatting consistency with sibling `docs/en/*/README.md` docs and the `docs/en/log/` baseline
- Package-facing tone for `docs/en/**` (no monorepo-only workflow unless explicitly scoped)

**Out of scope:** pedagogy, factual correctness, or whether an example overpromises behavior; those belong to `review`.

### `review` — content / accuracy reviewer

**Output bias:** “Would a reader misunderstand this, trust something false, or miss a required step?”

**In scope:**

- Clarity: skipped steps, overloaded sentences, ambiguous “this” / “it”, or hidden prerequisites
- Example credibility: identifiers, commands, and behavior vs codebase or authoritative discovery commands; illustrative vs runnable paths labeled
- Cross-doc consistency: adjacent package docs, linked guides, and PHPDoc-level contracts when the doc asserts behavior
- Code-backed identifiers: classes, methods, config keys, commands, and defaults must be verified or explicitly marked illustrative

**Out of scope:** pure formatting or TOC layout unless it causes wrong navigation; then `check` owns it.

## Inputs and Scope Resolution

- Target doc path, component name, or active review queue
- Standards source: `standards/DOCS_STANDARDS.md`
- Concrete path provided: review it first
- Package name only: map to the package primary doc path
- No path: start from the first unresolved doc in default order
- When continuing, include concise progress: `current`, `next`, `remaining`
- Model mapping:
  - `check=default`
  - `review=default`

## Finding Contract

1. Both outputs must use `Bugs`, `Inconsistencies`, and `Improvements`.
2. Each finding must include severity, evidence, and a concrete suggestion.
3. Use stable severity thresholds:
   - `High`: wrong behavior, wrong command/config/API/default, broken route, or damaged primary success path
   - `Med`: structure or consistency issue that does not break the main path but can mislead, slow, or fragment understanding
   - `Low`: wording, pacing, emphasis, or readability improvement
4. Merge and deduplicate by evidence.
5. Keep only high-confidence findings in the action queue.
6. Avoid speculative issues; when evidence is insufficient, mark `needs verification`.
7. Default to one batch decision after the merged report:
   - `A` = accept all findings
   - `B` = reject all findings
   - `C` = continue discussion
8. If the user gives a direct edit instruction, follow it instead of forcing `A/B/C`.
9. After confirmation to fix, switch to the `sps-docs` edit/alignment workflow and limit edits to the approved audit scope unless the user expands scope.

## Low-risk Auto-fix Exception

When the user explicitly authorizes auto-fix or unattended execution, you may fix immediately without another confirmation only if all are true:

- the issue is doc-only, or the code change is about 3 lines or fewer
- risk is clearly low
- the fix has a meaningful verification path

If code changes are involved:

- add or update the relevant test first
- rerun the relevant verification
- report the result after the fact

If any of those conditions fail, return to the normal confirmation flow.

## Output Template

```markdown
## High-Confidence Findings

### Bugs
- [High] <issue> | Evidence: <path + snippet> | Suggestion: <fix>

### Inconsistencies
- [Med] <issue> | Evidence: <path + snippet> | Suggestion: <fix>

### Improvements
- [Low] <issue> | Evidence: <path + snippet> | Suggestion: <fix>

## Confirmation Decision
- `A` Accept all
- `B` Reject all
- `C` Continue discussion
```
