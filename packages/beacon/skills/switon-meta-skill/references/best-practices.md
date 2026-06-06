# Best practices for skill creators (snapshot)

Snapshot from <https://agentskills.io/skill-creation/best-practices>. Conventions in this skill extend these. Re-sync
when the upstream doc changes.

---

## Ground skills in real expertise

- **Extract from a hands-on task:** Complete a real task with the agent, then extract the reusable pattern. Capture
  context you provided, input/output formats, corrections you made, and the steps that worked.
- **Synthesize from project artifacts:** Use real failure cases, version history, code review comments, API specs,
  runbooks — not generic articles.

## Refine with real execution

Run the skill on real tasks and feed results back into the creation process. One pass of execute-then-revise improves
quality. For structured iteration (test cases, grading),
see [Evaluating skill output quality](https://agentskills.io/skill-creation/evaluating-skills).

## Spending context wisely

- **Add what the agent lacks, omit what it knows.** Focus on project-specific conventions, domain procedures,
  non-obvious edge cases, and which tools/APIs to use. Cut anything the agent would get right without the instruction.
- **Design coherent units.** A skill should encapsulate one coherent unit of work. Too narrow → multiple skills load for
  one task; too broad → hard to activate precisely.
- **Aim for moderate detail.** Concise, stepwise guidance with a working example tends to outperform exhaustive docs.
  When covering every edge case, consider leaving most to the agent’s judgment.
- **Structure with progressive disclosure.** Keep `SKILL.md` under 500 lines. Move detail to `references/` and tell the
  agent **when** to load each file (e.g. "Read `references/api-errors.md` if the API returns non-200"), not just "see
  references/."

## Calibrating control

- **Match specificity to fragility.** Give freedom when multiple approaches are valid; be prescriptive when operations
  are fragile or consistency matters.
- **Provide defaults, not menus.** Pick a default and mention alternatives briefly (e.g. "Use pdfplumber; for OCR use
  pdf2image + pytesseract").
- **Favor procedures over declarations.** Teach *how to approach* a class of problems, not only *what to produce* for
  one instance. Output templates and constraints are still valuable.

## Patterns for effective instructions

- **Templates for output format:** Provide a concrete template so the agent pattern-matches; long templates can live in
  `assets/` and load on demand.
- **Checklists for multi-step workflows:** Explicit checklist with dependencies and validation gates (e.g. "Step 1: run
  script X; Step 2: edit Y; Step 3: run validator").
- **Validation loops:** Do the work → run a validator (script or reference checklist) → fix → repeat until pass.
- **Plan-validate-execute:** For batch/destructive ops, have the agent produce a plan, validate it against a source of
  truth, then execute. Validation script should give clear errors so the agent can self-correct.
- **Bundling scripts:** If the agent repeatedly reinvents the same logic across runs, bundle a tested script in
  `scripts/` and instruct the skill to use it.

## Next steps (upstream)

- [Optimizing skill descriptions](https://agentskills.io/skill-creation/optimizing-descriptions)
- [Evaluating skill output quality](https://agentskills.io/skill-creation/evaluating-skills)
- [Using scripts in skills](https://agentskills.io/skill-creation/using-scripts)
