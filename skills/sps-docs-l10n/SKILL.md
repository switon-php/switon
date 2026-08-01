---
name: sps-docs-l10n
description: Objective-driven localization for switondown docs. Translate and align non-English trees while maintaining absolute semantic parity with English.
---

# Switon Docs Localization (Objective-Driven)

**Status: retired for published docs.** `docs/zh-CN` and `docs/ja-JP` are removed; do not recreate locale trees. Use `sps-docs` + `packages/{name}/docs/` instead.

**Core Objective**: Produce translated Markdown documentation (`docs/{locale}/**`) that feels 100% native to the target language, while maintaining absolute semantic, structural, and terminology parity with the authoritative English source (`docs/en/**`).

*Premise: The English docs are the single source of truth for architecture and APIs. The localization's job is not to improve the English logic, but to transfer its meaning flawlessly.*

## Your Mission
You are a bilingual Principal Engineer. When localizing or reviewing translated documentation, your goal is to ensure the translation is structurally identical to the source, uses consistent domain terminology, and sounds like it was written by a senior developer native to that language.

## Evaluation Criteria (What good looks like)
Use your professional judgment to uphold these translation pillars:

1. **Semantic Drift is a Bug**: The localized document must not hallucinate new features, omit edge cases, or subtly change the meaning of technical constraints (e.g., confusing "MUST" with "SHOULD").
2. **Terminology Consistency**: Terms like "Dependency Injection", "Container", or "Routing" must be translated consistently across all files using the locale's `terms.md` baseline. Never invent new translations for established framework concepts.
3. **Structural Parity**: The translated document must mirror the English markdown structure exactly. Anchors (`{#anchor}`), code block languages, and relative links must remain unbroken.
4. **Native Developer Tone**: The prose should sound professional, concise, and active. Avoid literal word-for-word machine translation that feels unnatural to a native programmer.

## How to execute
Do not follow a rigid mechanical sequence. Based on the requested scope:
- **Small local edits**: Read the source, apply terminology constraints, and execute the translation directly.
- **Default path**: Use single-agent direct execution. Read only the English source files needed for the owned scope, then translate and verify inside the same session.
- **Broad multi-package runs**: Still prefer single-agent execution. Only load grouping guidance when the scope is large enough that package ordering or terminology clustering materially helps.
- **Quiet Execution**: Do not echo translated markdown back into the chat. Use file editing tools silently.

## Reference Materials & Validation Scripts
While you are free to translate autonomously, you MUST respect these established baselines:
- Consult `references/terms.md` and `references/translation.md` to ensure you are not violating core translation style.
- Consult `references/grouping.md` only for large multi-package runs where grouping affects execution order or terminology continuity.
- To validate structural parity after translations, you may use the validation scripts:
  - `python3 scripts/docs/l10n_anchor_parity.py --locale <locale>`
  - `python3 scripts/docs/markdown_link_audit.py --tree docs/<locale>`

## Verification & Reporting
When reporting back or reviewing an existing locale:
- Classify any found issues using the standard severity scale: `P0 (Semantic Drift)`, `P1 (Terminology Inconsistency)`, `P2 (Non-native Wording)`, `P3 (Polish)`.
- If the work is clean, report: "No semantic drift vs English found." Lead with conclusions, be concise.
