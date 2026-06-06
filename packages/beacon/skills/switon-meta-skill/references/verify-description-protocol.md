# Verify description — protocol

Protocol for the runbook in [verify-description.md](verify-description.md). Use when reimplementing in another language
or tooling.

**Purpose:** Test that the AI selects the right skills/references from question text only (no gold, no codebase).

## Data format

- **questions.json**: `["question1", "question2", ...]`
- **gold.json**: `[["switon-php-coding: reference1"], ["skill: reference2", "skill: reference3"], ...]` — same length as
  questions; each element is an array of one or more answer strings.
- **answers.json**: Same structure as gold (output of the answering agent).

## Generate

Input: directory of `*-questions.md` files, and a limit. Reference from filename (`<reference>-questions.md`); questions
from numbered lines (`N. text`). Merge all files, shuffle, take first `limit` items. Output: `questions.json`,
`gold.json` into one output dir. Script prints path (line 1) and actual question count N (line 2). N may be less than
the requested limit (e.g. limit 100 but source had 10 → N=10). Runner must pass this actual N to the answering agent,
not the limit.

## Verify count

Input: output dir path; optional `expected_N`. Read `questions.json` and `gold.json`; print questions count and gold
count. If `expected_N` given, exit 0 only when both equal it. Runner uses the printed count as N for the answering
agent (do not trust generate’s limit alone).

## Grade

- Input: output dir path only. Script reads `gold.json`, `answers.json`, `questions.json` from that dir by convention.
  Lengths may differ (e.g. source had fewer questions than requested).
- Rule: for each index i in 0..min(len(gold),len(answers))-1, hit iff every string in gold[i] appears in answers[i] and
  answers[i] has at most 2 refs.
- Output: if lengths differ, report "Length mismatch: gold X, answers Y; grading first Z items." Then total (graded
  count); hits (strict); hit rate; two dimensions — 1 ref, 1–2 refs (1–2 refs includes 1 ref; at least one in gold).
  Both dimensions use denominator = graded total. When there are strict misses: a one-line **miss summary** (expected →
  got with counts), then for each strict miss: question text, expected, got. Exit 1 if any miss or length mismatch.

## Answering agent (file-only I/O)

- **Runner:** Give the agent (1) the **output dir path**, (2) the **number of questions N**, (3) the requirement:
  “answers.json must have exactly N elements; if you output a different count, you must explain why in your reply.” Do
  not pass question content.
- **Agent input:** read `questions.json` from that path.
- **Agent output:** write `answers.json` to that path: array of arrays, **exactly N elements**, same order as questions.
  If count cannot match N, explain why in the reply. **Reference** = filename without `.md` (e.g.
  `verify-description.md` → `verify-description`).
- Forbidden: any tools or file access other than read `questions.json` and write `answers.json` in the given dir.
