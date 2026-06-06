# Translation

Use this reference for **how to translate** Switon docs safely and naturally.

## Source of truth

- Treat `docs/en/**` as the only factual source of truth.
- If `docs/{locale}/terms.md` exists or may need updates, follow `references/terms.md` before making terminology decisions.

## Semantic safety vs. Tone

- Keep semantics, constraints, defaults, order of operations, and scope aligned with English.
- **CRITICAL - Tone & Fluency:** Do NOT translate word-for-word. The final output must read like it was written by an experienced local developer writing a professional technical manual. Ensure a restrained, information-first tone that matches the English source.
- Break up long, nested English sentences into shorter, punchier native sentences if needed for clarity.
- If English is ambiguous or wrong, fix English first or record an explicit decision; do not silently change meaning only in the locale.

## Never translate these

- classes, methods, config keys, CLI commands
- exception names, constants, FQCNs
- paths, URLs, anchors
- executable code, placeholders, command arguments

## Hybrid translation / code-switching

Use hybrid translation by default for long-tail or low-lexicon locales.

- Keep established IT and framework terms in English unless the locale already has a clearly established native form in real developer usage.
- Do **not** invent localized jargon for concepts such as Middleware, Dependency Injection, Controller, Service Provider, or Context.
- If unsure, preserve the English term.
- Naturalness means native grammar, sentence order, collocations, and connective tissue around the English term.
- "Native technical prose" does **not** mean "translate every technical noun".
- For search-poor locales, prefer semantically safe hybrid prose over fragile full translation.

## Formatting retained English terms

- If the target language uses a **non-Latin script**, do **not** bold general English concepts.
- If the target language uses a **Latin script**, use bold for general English IT concepts such as `**Middleware**`.
- If the term is an actual code entity, always use backticks such as `` `MiddlewareInterface` ``.

## Style defaults

- Optimize for **native developer readability**, not full lexical localization.
- Prefer natural target-language syntax and information order over English-shaped wording.
- Keep established English jargon when the locale really uses it.
- Follow the target locale's punctuation and typography in prose.
- Translate explanatory comments in code examples only when helpful; never translate executable code.
- If naturalness conflicts with jargon localization, keep the English jargon and naturalize the surrounding prose.

## Mirroring rules

- Keep document structure, links, and ordered `{#...}` anchors aligned with English unless the user explicitly asks for structural changes.
- Allow sentence regrouping inside a section when needed for natural target-language prose.
