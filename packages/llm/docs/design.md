# Design Rationale {#design-rationale}

`switon/llm` keeps model lookup in one catalog and the chat call on the model you named.

## See also {#see-also}

- [LLM Package](README.md) — usage and configuration
- [FAQ](faq.md#llm-faq) — setup and lookup

---

## Core Problem {#core-problem}

Chat setup drifts once an app talks to more than one host: hard-coded URLs, short names that silently pick the wrong model, and window or media flags guessed from the model string.

## Primary Goal {#primary-goal}

Name a model, call it, and leave the agent loop and thread storage outside this package.

- **Benefit:** one catalog, one lookup rule.
- **Cost:** window, image, video, and effort levels are written on the model. They are not inferred.

## Core Choice {#core-choice}

The catalog is filled at boot. The host is contacted only when a reply is requested.

An empty name is the first model. Any other unknown name is rejected. The first declaration of an alias wins.
