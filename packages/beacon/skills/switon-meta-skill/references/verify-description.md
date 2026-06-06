# Verify description

**Description test** (one test, two dimensions):

1. **Skill trigger** — Does the skill load when it should?
2. **Reference selection** — Once loaded, does the agent choose the right reference(s) for the question?

Both are covered by the pipeline below (answers = skill + reference names). Run in a **subagent** from the skill
directory (meta-skill root). Script paths relative to skill root.

## Data (needed when running)

- **questions.json**: JSON array of strings. Example:
  `["Add a nickname to User entity.", "Which interface has get/set for locale?"]`
- **gold.json**: JSON array of arrays of strings; same length as questions. Example:
  `[["switon-php-coding: entity"], ["switon-php-coding: i18n"]]`
- **answers.json**: Same format as gold; written by the answering agent in the **same directory** as `questions.json`.
  Each string is `skill: reference1,reference2`; one question can have multiple. **Reference** = filename without
  `.md` (e.g. `verify-description.md` → reference `verify-description`).

Checking: all three are JSON arrays; questions has N strings; gold has N elements. answers may have fewer or more (e.g.
source had fewer questions); when lengths differ, grade script scores the overlapping segment and reports the mismatch.

## 1. Generate

```bash
php scripts/verify_description_generate.php ../switon-php-coding/references 30
```

Script prints line 1: output dir path; line 2: **actual** number of questions N (may be less than the limit you passed,
e.g. source had only 10). Produces `questions.json`, `gold.json`. **Runner:** Use this N for the agent, not the
requested limit.

## 1b. Verify count (only when needed)

```bash
php scripts/verify_description_count.php <output-dir> [expected_N]
```

Run only when you need to confirm (e.g. agent’s answer count doesn’t match N, or you don’t trust generate). Reads
`questions.json` and `gold.json`; prints `questions: X`, `gold: Y`. If `expected_N` given, exit 0 only when both equal
it.

## 2. Answering agent (file-only I/O)

**Runner:** Pass to the agent (1) the **output dir path** (line 1 of step 1), (2) the **actual N** from line 2 of step
1 (not the limit you passed to generate — e.g. you asked for 100 but got 10, so tell the agent 10), and (3) this
requirement: “answers.json must be an array of exactly N elements, one per question in order. If for any reason you
output a different number of elements, you must explain why in your reply.” Do **not** paste question content into the
prompt.

**Agent:** Read `questions.json` from that path; write `answers.json` to the same path: array of arrays, **exactly N
elements** (one per question), same order. If the count cannot match N, explain why in the reply. **Reference** =
filename without `.md` (e.g. `verify-description.md` → `verify-description`). Answer may list 1–2 refs; hit if it
contains all gold refs and at most 2 refs.

**Forbidden:** any tools or file reads other than reading `questions.json` and writing `answers.json` in the given dir.

## 3. Grade

```bash
php scripts/verify_description_grade.php <output-dir>
```

`<output-dir>` is the path printed by step 1. Script reads gold.json, answers.json, questions.json from that dir by
convention. If gold and answers length differ, grades only the overlapping segment and prints the mismatch. Prints
total (graded count); hits (strict); hit rate; two dimensions — 1 ref, 1–2 refs (1–2 refs includes 1 ref; at least one
in gold). When there are strict misses: a **one-line miss summary** (expected → got with counts, e.g.
`expected router → got i18n (5), expected i18n → got router (3)`), then the detailed Misses list (index, expected, got;
with `questions.json`, each includes question text). **Report to user:** Table: 指标 / 结果. Rows: 总题数（参与评分）、1
ref 命中率、1–2 refs 命中率（含分母）. Omit 严格命中 and 严格命中率. Then miss summary and length match if applicable.
Example:

| 指标           | 结果             |
|--------------|----------------|
| 总题数（参与评分）    | 50             |
| 1 ref 命中率    | 90.00% (45/50) |
| 1–2 refs 命中率 | 94.00% (47/50) | **After grade:** if any miss or length mismatch, analyze the summary and Misses and consider refining skill-trigger or reference-selection keywords.

Protocol (data, generate, grade, reimplement): [verify-description-protocol.md](verify-description-protocol.md).
