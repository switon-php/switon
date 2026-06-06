# Creating scenario questions for a skill

Optional file to validate that the skill’s references are enough: `references/<topic>-questions.md` (e.g.
`router-questions.md`, `i18n-questions.md`). **Format example:** **switon-php-coding
** → [router-questions.md](../../switon-php-coding/references/router-questions.md), [i18n-questions.md](../../switon-php-coding/references/i18n-questions.md) (
numbered questions + Flow block at top).

## When to add

When the skill has references that should be testable (e.g. API, CLI, concepts). Offer after creating the skill; user
decides.

## How to create

- **Location** — `references/<topic>-questions.md`, one file per topic.
- **Numbering — use category.sub.** When generating or editing scenario questions, **always** use **category.sub** (e.g.
  `1.1`, `1.2`; `2.1`, `2.2`). First number = section/category, second = question index. Section heading format:
  `**1. Category name**`, `**2. Category name**`, … Questions under that section: `1.1. …`, `1.2. …`, then `2.1. …`,
  `2.2. …`. Adding or removing questions only changes that category’s sub-numbers. Required for reporting and
  verification.
- **Format** — One question per line, English. Answer = whole skill's refs (they may cross-reference). How to run =
  verify-reference; no need to name a ref in the questions file.

## What to write

- **User-facing** — Scenarios an agent would face when using the skill (e.g. "How do I resolve path X with verb Y?", "
  Which class handles Z?").
- **Reference in practice** — Under default/silent conditions, test whether the agent’s use of the reference leads to
  correct answers; verification against code shows if it holds up.
- **Flow / multi-class** — Include questions that require tracing a flow or reasoning across multiple classes (e.g. "
  From request X to response Y, which classes and methods?", "Call chain for Z?"). Not only single-reference lookups.
- **Mix all types** — Include user-facing, reference-in-practice, and flow/multi-class; don’t only write one kind (e.g.
  don’t only write flow questions). Cover main flows and edge cases. More the better — e.g. 100 is not many. We don’t
  require 100% correct.
- **From other PHP frameworks** — When the topic has counterparts in Laravel, Symfony, or other PHP frameworks, add a
  category of questions for those users (e.g. "In Laravel we do X. How do we do that here?", "In Symfony we have Y.
  What’s the equivalent here?"). One section (e.g. "From other PHP frameworks") with numbered items in category.sub.
- **Order** — Easy to hard (e.g. single-reference first, then flow / multi-class).
- **Good questions** — One clear question per line; add cases when verification or use reveals gaps.
- **Silent tests** — Do **not** tell the agent which `references/*.md` file to read in the question text. Questions
  should be answerable in a silent setting where the agent infers and locates the relevant reference/code/tools itself.

## Run and check

[verify-reference.md](verify-reference.md): runner specifies **answer ref** and **verify = code**. Flow details live in
each `*-questions.md`.

**Flow line convention (top of `*-questions.md`):**

- `**Generate:** switon-meta-skill::questions-author. **Verify:** switon-meta-skill::verify-reference.`

**Random sample:** `php scripts/sample_scenario_questions.php <topic>-questions.md <output.md> [count]`. Output is
questions only (no Flow block). Flow lives in the **source** `*-questions.md`; do not add it to the sample—the sample is
the test input. Then use output path as questions file; same answer ref and verify = code.

