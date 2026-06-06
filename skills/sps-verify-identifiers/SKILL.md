---
name: sps-verify-identifiers
description: Strict Switon identifier verification and anti-borrowing guard. Use when reviewing, writing, or fixing docs, PHPDoc, examples, reviews, or AI-facing text that mention classes, methods, functions, attributes, config keys, commands, events, exceptions, namespaces, or any other machine-meaningful identifier. Every such identifier must be verified against current Switon source or an authoritative discovery command; borrowed Laravel/Symfony/Hyperf-shaped APIs, near-Switon names, and invented identifiers are failures.
---

# Switon Identifier Verification

## Goal

Prevent invented, borrowed, or near-Switon identifiers from entering docs, PHPDoc, examples, reviews, or AI-facing guidance.
Also prevent semantically misleading identifier names that violate Switon naming and DI conventions.

Switon is a standalone framework at the same layer as Laravel, Symfony, and Hyperf. It is **not** a Laravel variant, so do not borrow their class names, helper names, config shapes, controller conventions, or mental shortcuts unless the current Switon source proves the exact identifier exists.

## When to Use This Skill

- The task asks to verify classes, methods, functions, config keys, commands, events, exceptions, attributes, namespaces, routes, or example code.
- The task asks to check for hallucinations, borrowed framework idioms, or non-Switon identifiers.
- You are editing or reviewing `docs/**`, PHPDoc, package READMEs, tutorials, audit findings, or generated examples.
- The wording “verify”, “source-backed”, “anti-hallucination”, “do not turn this into Laravel”, “identifier verification”, or similar appears.

## Required Pre-reads

Read, in order:

1. `standards/README.md`
2. `standards/DOCS_STANDARDS.md`
3. `skills/README.md`

If the scope includes PHPDoc, also read `standards/PHPDOC_STANDARDS.md`.
If the scope includes code or DI-facing examples, also read `standards/CODE_STANDARDS.md`.

## Non-negotiable Rules

- Every machine-meaningful identifier must be verified against source or an authoritative discovery command.
- Do not accept “close enough” names, framework-shaped guesses, or aliases that only sound plausible.
- Do not borrow identifiers from Laravel, Symfony, Hyperf, Doctrine, Twig, Blade, or other frameworks unless the exact identifier exists in Switon source.
- Do not accept semantically misleading names when the repo already defines a naming convention or DI slot convention.
- Do not use character-level heuristics such as “contains `*`” or “contains `{`” to skip verification. Classify by meaning and syntax pattern first.
- If an identifier cannot be verified, either:
  - remove it,
  - replace it with a verified Switon identifier, or
  - mark it clearly as illustrative with placeholders.
- General concepts may be compared across frameworks, but concrete symbols must stay Switon-native and code-backed.

### Verification boundary: exact symbol vs family vs placeholder

Not every backticked token is a concrete symbol that should resolve to one code object.
Before verifying, classify the token by intent:

- **Exact symbol** — verify strictly against code or discovery commands.
  Examples: `Switon\Routing\RouterInterface`, `Switon\Routing\RouterInterface::getPrefix()`, `cache:clear`, `Switon\Http\RequestHandlerInterface`
- **Namespace family shorthand** — allowed when it clearly means “items under this namespace”, not one concrete class.
  Examples: `Switon\Core\Contract\*`, `Switon\Validating\Attribute\*`
- **Illustrative placeholder** — allowed when it clearly marks a slot, template key, anchor, or example-only value.
  Examples: `<id>`, `<path>`, `<command-name>`, `{field}`, `{value}`, `{#option-discovery}`
- **Invalid / borrowed / fake exact symbol** — report or rewrite.
  Examples: invented FQCNs, near-Switon aliases, borrowed Laravel/Symfony class names, or wildcard shorthand presented as if it were one real class

### Allowed relaxed patterns

The following patterns may bypass exact symbol lookup when their intent is obvious in context:

- backticked namespace-family shorthand ending with `\*`
- angle-bracket placeholders such as `<id>`, `<path>`, `<category>`, `<command-name>`
- message/template placeholders such as `{field}`, `{value}`, `{file}`
- heading anchors such as `{#installation}`, `{#see-also}`

These are still subject to meaning checks:

- a namespace-family shorthand must refer to a real namespace family that exists in Switon source
- a placeholder must read as a placeholder, not as a fake “almost real” symbol
- if a token is ambiguous between a real symbol and a placeholder, prefer rewriting it to remove ambiguity

### Naming-convention checks

When an identifier is real but its name is still under review, verify that the name matches repository conventions and semantic intent.

Examples:

- injected repository property names should read like repository slots, for example `$userRepository`, not ambiguous collection nouns such as `$users`
- injected interface properties should reflect the dependency role, not a vague alias
- config-injected scalar or array properties should prefer clear single-word names when the meaning stays obvious
- do not rename identifiers into Laravel/Symfony-shaped names just because they feel familiar

## What Must Be Verified

Verify any code-like or contract-like item, including:

- classes, interfaces, traits, enums, attributes, namespaces
- methods, functions, helpers, constants, properties
- config keys, option names, environment variables, aliases
- commands, CLI entrypoints, event classes, exception classes
- route attributes, DTO attributes, response payload keys when presented as contract
- package names, file paths, example snippets, default values

Do not require exact symbol lookup for:

- namespace-family shorthand such as `Switon\Core\Contract\*`
- explicit placeholders such as `<id>` or `{field}`
- Markdown anchors such as `{#quick-start}`

## Evidence Sources

Use the strongest available source:

1. current Switon source files under `packages/*` and `docs/`
2. authoritative discovery commands such as `class:*`, `event:*`, `tool:*`, `router:*`, `config:options`
3. targeted package tests when they prove behavior or output shape

Do not use external framework memory as evidence.

## Workflow

1. Enumerate every machine-meaningful identifier in scope.
2. Mark each item as one of:
   - verified Switon identifier
   - verified namespace family shorthand
   - illustrative placeholder
   - invalid / borrowed / unverified
3. Verify each real identifier against source or discovery commands.
4. For namespace-family shorthand, verify the namespace family exists and that the prose does not present it as one exact class.
5. For placeholders, verify that they are clearly signaled as placeholders and do not impersonate real symbols.
6. Check surrounding behavior claims too:
   - method existence is not enough if defaults, merge order, return shape, or trigger timing are also claimed
7. Check naming semantics where conventions exist:
   - for DI properties, prefer semantic slot names
   - for examples and docs, prefer names that reflect the actual injected type or role
8. Remove or rewrite any invalid / borrowed / unverified item immediately when editing is allowed.
9. If working read-only, report only evidence-backed findings.

## Failure Patterns To Catch

- Laravel/Symfony/Hyperf helper names presented as if native Switon APIs
- near-Switon names that look credible but are not real
- invented config keys or defaults
- semantically misleading dependency property names, for example repository dependencies named like plain collections
- controller / DTO / event / exception names copied from another framework style
- examples that imply a contract not proven by source
- “friendly aliases” that do not exist in code
- wildcard namespace shorthand misread or reported as if it were one missing class
- placeholders that look too real, for example `YourCommandInterface` instead of `<command-interface>`

## Output Expectations

When auditing, report:

- `Verified`: what is confirmed and by what source
- `Allowed shorthand`: namespace families or placeholders that are intentionally non-exact and why they are acceptable
- `Issues`: invalid, borrowed, or unverified identifiers
- `Residual risk`: any area not fully proven

When editing, keep the result Switon-native, source-backed, and explicit about any illustrative placeholders.
