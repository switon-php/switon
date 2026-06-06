---
name: sps-phpdoc
description: Objective-driven PHPDoc maintenance. Write, verify, and optimize PHPDoc in framework packages for AI discoverability and contract clarity. 
---

# Switon PHPDoc Optimization (Objective-Driven)

**Core Objective**: Ensure PHPDoc in `packages/*/src/` serves as a high-signal, low-noise contract for both human developers and AI agents. 

*Premise: PHPDoc should explain the "Why", the "Contract", and "How to use it", not repeat the PHP type hints or document volatile implementation details.*

## Your Mission
You are a Staff Engineer reviewing PHPDoc. Read the class or method. 
Your goal is to optimize it so that an AI or human can perfectly understand how to implement or consume the API *without* needing to read the internal source code.

## Evaluation Criteria (What good looks like)
Use your judgment to align the PHPDoc with these architectural principles:

- **High Signal / Low Noise**: Does the `@var` or `@param` just repeat the type hint? Delete it. Does the class-level docblock repeat "Same as method X"? Consolidate it.
- **Contract Stability**: Are volatile implementation details (like cache file paths or low-level flags) polluting the class-level summary? Move them down to the specific method.
- **AI-Friendly Boundaries**: Is the behavioral contract clear? (e.g., "MUST NOT throw", "Thread-safe"). If not, add it.
- **Class-Type Empathy**: 
  - *Exceptions/Events*: Keep them brutally concise (1 sentence). 
  - *Core Classes*: Provide rich usage examples and `@see` links.
  - *Commands*: Write the summary as if it's CLI help text (user action verbs, no HTML tags).

## How to execute
Do not follow a mechanical checklist. Read the target code, understand its domain purpose, and ruthlessly trim redundancy while adding missing constraints. 
**Litmus Test**: After your optimization, ask yourself: *"Can I implement or call this correctly from this PHPDoc alone?"* If not, you trimmed too much or missed adding the contract.

## Verification & Reporting
If asked to verify without editing:
- Diagnose if the PHPDoc matches the actual code implementation (check types, `@throws` vs actual `throw` statements).
- Report discrepancies as "Verified" or "Issues found", citing specific lines and suggesting the exact fix.