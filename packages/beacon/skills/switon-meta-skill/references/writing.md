# Writing a skill (create or update)

## 1. Layout and naming

- This reference is for app skills and Beacon built-ins only. Repo-root framework-development skills in the Switon
  monorepo are out of scope.
- App: `skills/app-*` at project root. Built-in app-facing Switon skills: Beacon `switon-*`. Folder = frontmatter
  `name`. One level under skill root.
- Body + references: English only; other languages only in `description` (triggers). First class/interface mention:
  FQCN. Triggers and "when to use" → `description`.

## 2. Quality

- One coherent unit; add only what the agent lacks. SKILL.md under ~500 lines; detail in references/. Default + brief
  alternatives (not a menu). Use templates, checklists, validation loops as needed.
  More: [conventions.md](references/conventions.md), [best-practices.md](references/best-practices.md).

## 3. Framework app-facing skills

- No doc paths in body. Point to **concrete code** (which class, interface, or path) and **mention the relevant concepts
  **; package↔namespace → `packages:official`. Location: Beacon `switon-*` built-ins.
  Full: [framework.md](framework.md).

---

## 4. Anatomy of the SKILL.md body

- Purpose (short); full "when" in description. Workflow/steps. Edge cases. Output format (template or I/O examples).
  When to read references/scripts (and whether **execute** or **read**).
- Patterns: template · I/O examples · checklist · conditional · validation loop. Conciseness: domain-specific only;
  skill must earn context. Freedom: high/medium/low — match task.

---

## 5. Creating a skill: interactive flow

With the user; confirm, don't assume. Prefer thoroughness.

**5.1 Capture intent** — What, when (→ description), output format, edge cases, scope (app `skills/app-*` or Beacon
built-in `switon-*`). From conversation: extract tools, steps, I/O; fill gaps; confirm. Offer to capture current
workflow as skill when relevant.

**5.2 Draft** — Built-in app-facing → Beacon `switon-*`. Propose name (folder = name, prefix) and description; confirm.
Write SKILL.md (+ references/, scripts/ as needed). Confirm; iterate.

**5.3 Before finalize** — Read as agent: unambiguous? Execute vs read clear? Trim redundancy. Refs one level; consistent
terms; FQCN on first class/interface mention. Optional: 2–3 scenario questions run in subagent.

**5.4 Done when** — Intent and "when" in description; body has workflow, edge cases, output (or pointer); executable
without guessing; user confirmed. Do not: assume requirements; put "when" only in body. Optional: feedback loop;
repeated helper → `scripts/`.

**After creating:** Proactively ask whether to add scenario questions and whether to run a test now. How to write
scenario `*-questions.md`: [questions-author.md](questions-author.md). How to verify
trigger: [verify-description.md](verify-description.md). How to verify reference
content: [verify-reference.md](verify-reference.md).

---

## 6. Creating from app code

Codify practice / reduce tediousness: (1) Scope with user (2) Analyze `app/`, `config/` (3) Summarize pattern; confirm (

4) Generate skill in `skills/app-<name>/`; optional 1–2 examples (5) Confirm. No invented patterns.

## 7. Updating a skill

**Target:** "meta-skill"/"switon-meta-skill" → this skill. "[name]" → that skill in app `skills/` or Beacon.
Unspecified → other in app `skills/`; ask or list if ambiguous.

**Flow:** Read SKILL.md + app code → compare → propose → **confirm** → apply. Do not contradict app usage unless user
wants both changed.
