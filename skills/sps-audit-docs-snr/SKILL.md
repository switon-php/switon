---
name: sps-audit-docs-snr
description: Evaluate documentation against top-tier industry standards to maximize high-value Signal and ruthlessly eliminate Noise.
---

# Docs Signal-to-Noise Ratio (SNR) & Quality Audit

**Core Objective**: Evaluate documentation against top-tier industry standards (Diátaxis, Google Developer Style, Stripe API docs) to maximize high-value "Signal" and ruthlessly eliminate "Noise".

## 1. The Diátaxis Framework Check (Content Purpose)
Industry standard dictates separating docs into 4 distinct types. A `README.md` should primarily serve as a **Quickstart (Tutorial)** and **How-to Guide**.
- **Identify Quadrant Bleed**: Is the document bogged down by deep architectural theories (Explanation) or exhaustive method tables (Reference)? These belong in separate files or PHPDoc.
- **Action**: Flag sections that drift from "How do I use this?" into "How did we build this?".

## 2. The Google Developer Style Check (Readability & Flow)
Evaluate the prose for cognitive load and user experience:
- **Front-Loading (The Elevator Pitch)**: Does the very first paragraph clearly state what the component is, why to use it, and when NOT to use it?
- **Active Voice & Brevity**: Are sentences short, direct, and active? (e.g., Use "The manager handles the cache" instead of "This class is used by the system to manage caching operations").
- **Prerequisites**: Are required dependencies, prior configurations, or assumed knowledge explicitly stated *before* the code examples?
- **Scannability**: Can a developer skim the document in 30 seconds using headers and bullet points, or are they facing intimidating "walls of text"?

## 3. The Stripe/Twilio Check (Code Snippet Health)
Evaluate the utility and realism of the code examples:
- **Illustrative vs. Runnable**: Do NOT force "Copy-Pasteability" (like forcing `class` wrappers, DI setup, or variable initialization) if the snippet is purely *illustrative* (e.g., showing a one-line `logger->info()`). Only Quick Start scripts require full runnable context. Adding boilerplate to illustrative snippets is itself NOISE.
- **Input/Output Clarity**: Are the inputs realistic? Is the expected outcome clear for complex operations?
- **The "Happy Path" First**: Is the most common, successful use-case presented *before* detailing edge cases, error handling, and complex configurations?

## 4. The Anti-Pattern Radar (Excess Noise to Delete)
Flag any of the following for immediate deletion or heavy reduction:
- **Trivial Output Bloat (CLI/Logs)**: DO NOT document exact terminal outputs for trivial/self-explanatory commands (e.g., `cache:list` or `make:controller`). Simply state that the command exists and what it does. Only show output traces if the output format itself is complex and crucial for the user to parse.
- **"Water is Wet" (Tautology)**: Explaining self-evident class or method names (e.g., "The `save()` method saves the data").
- **Framework Navel-Gazing**: Over-explaining internal abstract classes, dependency wiring, or lifecycle hooks that a standard consumer never directly interacts with.
- **The History Lesson**: Explaining how the component used to work in older versions. Users only care about the current state.
- **API Dictionary Dumps**: Exhaustive lists of methods or parameters that modern IDE auto-complete or source code comments handle better.
- **Unjustified Onboarding Card**: A `First 10 minutes` section added without clear onboarding-risk trigger. If present, it must stay short (max 3 steps, ~6-10 lines) and must not duplicate Quick Start/FAQ.

## 5. Reporting Protocol
**CRITICAL: DO NOT modify any files.** This is a strict read-only audit.

Report your findings back to the main agent formatted EXACTLY like this:

### 1. Missing Signal (The "Google/Stripe" Gaps)
*What crucial context, prerequisites, 'Happy Path' examples, or boundary warnings are absent? Are code snippets lacking context or copy-pasteability?*

### 2. Excess Noise & Bloat (The "Diátaxis" Violations)
*What paragraphs violate the anti-patterns? What trivial CLI outputs, theoretical 'Explanations', or exhaustive 'References' should be removed to keep the focus on 'How-to'? List specific "Water is wet" or "Navel-gazing" sentences.*

### 3. Readability & Scannability
*Are there walls of text? Passive voice? Vague terminology? How can the formatting be improved for skimming?*

### 4. Content Strategy Verdict
*A one-sentence summary of the document's overall SNR health.*
