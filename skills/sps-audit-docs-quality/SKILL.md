---
name: sps-audit-docs-quality
description: Quality gate for docs. Judge whether documentation is good enough for a slow-moving, high-quality doc set by merging structure, signal-to-noise, docs UX, and user-value audits.
---

# Docs Quality Gate

## Objective

Answer the final question:

Even if the docs are true, are they good enough?

This gate exists because this repository values documentation quality highly and updates docs more slowly.
A doc set should not pass on correctness alone if the reading experience is still weak.

## Required Pre-reads

Read, in order:

1. `standards/README.md`
2. `standards/DOCS_STANDARDS.md`
3. `skills/README.md`

Then use these internal quality mechanisms as needed:

- `sps-audit-docs-structure`
- `sps-audit-docs-snr`
- `sps-audit-docs-ux`
- `sps-audit-docs-user-value`

## Quality Dimensions

### 1. Structure

Content belongs in the right document shape and does not blur `README.md`, `faq.md`, and `design.md`.

### 2. Signal-to-noise

The docs emphasize decisions, success paths, and meaningful boundaries instead of bloat or obvious explanation.

### 3. Docs UX

The first-run path, troubleshooting path, and decision flow help users succeed quickly.

### 4. User value

The docs feel useful, grounded, and worth reading, not merely technically correct.

## Decision Rule

This gate returns one of:

- `pass`
- `fail: structural weakness`
- `fail: quality below bar`
- `partial: truth passed but quality still needs work`

## Output Format

### Quality Strengths

### Quality Failures

### Gate Verdict

### Highest-Leverage Fix
