# Execution

Use this reference for **file-level localization inside an owned scope**.

## Execution responsibilities

- localize only the owned scope
- keep meaning aligned with English
- finish ordinary work inside the same ownership window
- report risk early instead of pushing weak output

## Scope discipline

- Stay inside the assigned package or package batch.
- Read only the English files needed for that owned scope.
- If baseline scouting is requested, inspect `README.md` only; ignore `faq.md` and `design.md` for anchor selection.

## Execution model

Complete file work using a **2-Pass workflow** to balance translation quality and token cost:

1. **Direct Quality Translation (Pass 1)**
   - Translate directly into natural target-language technical prose.
   - Embed `references/terms.md` decisions directly into the prose.
   - Do **not** output a separate literal semantic draft; aim for the final readable structure immediately.
2. **Anti-Regression Check (Pass 2)**
   - Review the generated translation exclusively against the English original for high-risk semantic drift.
   - Check strictly for: dropped negations, flipped conditions, changed quantifiers, altered defaults, and mismatched auth/security wording.
   - If semantic drift is found, apply the fix internally and output the corrected version. Do not output a lengthy diff report.

If a pass fails, fix it inside the same ownership window and rerun the relevant checks before moving on.

## Multi-package batches

- If assigned multiple packages, prefer related packages that share terminology or mechanisms.
- Process them in the planned cluster order rather than mechanical alphabetical order.
