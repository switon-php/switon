---
name: sps-audit-docs-structure
description: Ensure every component strictly adheres to the Holy Trinity document structure (README.md, faq.md, design.md) to maintain perfect separation of concerns.
---

# Docs Architectural Structure Audit

**Core Objective**: Ensure every component strictly adheres to the "Holy Trinity" document structure (`README.md`, `faq.md`, `design.md`) to maintain perfect separation of concerns and maximize readability.

## The Holy Trinity of Component Documentation

Every component in `docs/en/{component}/` should ideally distribute its content across three distinct files based on the user's intent. Your audit must flag content that is in the wrong file.

### 1. `README.md` (The "How-To" & Quickstart)
**Intent**: "I am busy. Show me how to use this immediately."
- **Must Have**: Elevator pitch (What & Why), Quickstart code (Happy Path), basic Configuration, and routing links to other components or files.
- **Optional**: `First 10 minutes` execution card only when onboarding-risk is clearly high.
- **Must NOT Have**: Deep architectural explanations, history lessons, or exhaustive troubleshooting steps.
- **If `First 10 minutes` exists**: keep max 3 steps and short execution-card style; do not duplicate FAQ troubleshooting trees or full Quickstart content.
- **Audit Action**: If you see paragraphs explaining *why* a certain design choice was made, or a long list of edge-case error resolutions, flag it to be moved out.

### 2. `faq.md` (Troubleshooting & Edge Cases)
**Intent**: "Something broke, or my use-case is weird. How do I fix it?"
- **Must Have**: Solutions to common errors, workarounds for edge cases, and CLI debugging tips.
- **Must NOT Have**: Basic usage tutorials.
- **Audit Action**: Ensure that error-handling bloat in the `README.md` is suggested to be migrated here. If `faq.md` does not exist but the `README.md` is full of troubleshooting, suggest creating it.

### 3. `design.md` (Architecture & Philosophy)
**Intent**: "I want to understand the engine, or I want to contribute to the framework."
- **Must Have**: Design rationale, why alternative approaches were rejected, internal lifecycle events, and abstract class hierarchies.
- **Must NOT Have**: "How to install" or basic usage.
- **Audit Action**: Extract any "Navel-Gazing" or deep theoretical explanations from `README.md` and suggest moving them here.

## Reporting Protocol
**CRITICAL: DO NOT modify any files.** This is a strict read-only audit.

Report your findings formatted EXACTLY like this:

### Missing Files
*Are `faq.md` or `design.md` missing when there is clearly content in `README.md` that belongs there?*

### Content Misplacement
*List specific sections in `README.md` that violate the Holy Trinity rule (e.g., "The 'Under the Hood' section in README.md should be moved to design.md").*

### Structural Verdict
*A concise summary of the component's structural health.*
