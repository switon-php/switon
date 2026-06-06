# Terms

Use this reference for **when and how to use `docs/{locale}/terms.md`**.

## Read / create / update rule

- If `docs/{locale}/terms.md` already exists, read it before any translation or review that touches repeated terminology.
- Create or update `docs/{locale}/terms.md` only when at least one of these is true:
  - the locale is new or the glossary is still thin
  - the scope spans multiple related packages
  - the same English term will recur across files or batches
  - the scope contains high-risk terms such as auth, security, DI, lifecycle, config, or exception vocabulary
  - delegated execution is explicitly re-enabled and executors need a shared terminology baseline
- Do **not** add one-off, obvious, or low-value terms just to make the glossary look complete.

## What belongs in `terms.md`

- stable translations or keep-in-English decisions for repeated framework vocabulary
- high-risk terminology decisions that must stay consistent across files or executors
- short notes only when needed to prevent semantic drift

## What does not belong in `terms.md`

- one-off wording choices used in only one paragraph
- low-risk common language that does not affect technical meaning
- long explanatory notes that belong in normal docs rather than the glossary

## Seeding order

- If the locale glossary is thin or new, seed from the Runtime/Core family first because it defines baseline framework vocabulary.
- For a broad run, establish the term baseline before scaling edits.

## Delegation rule

- Ignore this section unless delegated execution is explicitly re-enabled.
- If delegation ever returns, settle the shared term baseline before scaling broad execution.
