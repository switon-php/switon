# Layout, conventions, and pitfalls

## Namespaces and location

- **Scope of this meta-skill:** app skills and Beacon built-ins only. Repo-root framework-development skills in the
  Switon monorepo are out of scope here.
- **Decide scope first:** default to an app skill. Use a Beacon built-in only when the user clearly wants a reusable
  Switon-provided app skill shipped by `switon/beacon`.
- **App skill:** prefix `app-`; path `skills/app-<name>/` at the app project root (same level as `app/`).
- **Beacon built-in:** prefix `switon-`; path `skills/switon-<name>/` in the Beacon source, or
  `vendor/switon/beacon/skills/switon-<name>/` in applications.
- **Naming:** folder name = frontmatter `name`; hyphens only; single level under the skill root.
- **Layout:** `SKILL.md` required; `references/`, `scripts/`, `assets/` optional.
- **Install/update in apps:** `composer require switon/beacon` or `composer update switon/beacon`.

## Progressive disclosure and spec

- Context: name + description always; body when triggered; references/scripts/assets on demand. SKILL.md < ~500 lines;
  long → references/ by topic; refs >300 lines → ToC.
- **Spec:** [spec.md](spec.md); <https://agentskills.io/specification>. Refs: relative from skill root, one level (no
  deep chains). **Cross-skill:** say "skill" + name (e.g. "see **switon-php-coding** skill"), not a path.

## references/

- One file per topic (`references/router.md`, `references/orm.md`). SKILL.md says when to read which.

## Tools, scenario questions, scripts

- **CLI in refs:** One Tools section; one bullet per command (invocation + brief In/Out). No `php switon.php`; see
  AGENTS.
- **Scenario questions:** Optional `references/<topic>-questions.md`. Authoring
  guide: [questions-author.md](questions-author.md).
- **Scripts:** Repeated helper → `scripts/` and tell skill to use it.

## Conventions

- **Language:** Body + references: English only. Other languages only in `description` (triggers). Concise; no restating
  the obvious.
- **FQCN:** First mention of class/interface = FQCN; later short name if clear.
- **description:** Third person; WHAT + WHEN; trigger terms. "When to use" here, not in body. Pushy helps ("Use when
  user mentions X, Y, Z"). IDE skill guidance for examples if present.
- **Naming:** Specific over generic. SKILL.md concise; detail in references/.
- **Headings:** "And" often mixes two concerns; split (e.g. "Core API" + config as sub-bullet).
- **description example:** ✅ "Extract text/tables from PDFs, fill forms, merge. Use when PDFs, forms, or extraction."
  ❌ "Helps with documents."
- **Structure:** Lists over paragraphs; one idea per bullet.
- **Style:** Why over MUST/NEVER; imperative; template or I/O examples; general not overfit. Intent = content; no
  malware/misleading.

## Pitfalls

- Description vague → no trigger terms → rarely triggers.
- "When to use" only in body → move to `description`.
- Wrong scope/path → app without `app-`; Beacon built-in outside Beacon `skills/`.
- Body too long → move to references/; SKILL.md < ~500 lines.
- No output format → add template or I/O examples.
- Repeated helper → put in `scripts/`, tell skill.
- Too many options → default + one escape hatch.
- Time-sensitive → no "before date X"; use "Current" vs "Legacy".
- Inconsistent terms → one term per concept.
- Paths → forward slashes.
- Doc paths in body → no docs/en/ or "see docs § X"; rules in skill; point to code/PHPDoc; package↔namespace → *
  *`packages:official`**.
- No FQCN on first mention → first class/interface mention = FQCN.
