# Design Rationale {#design-rationale}

`switon/llm-html` keeps HTML-to-LLM conversion as one call over a document the caller already has.

## See also {#see-also}

- [LLM HTML Package](README.md) — usage and the result fields
- [FAQ](faq.md#llm-html-faq) — passthrough, links, and truncation

---

## Core Problem {#core-problem}

A fetched page is a poor LLM input.

- Navigation, ads, and cookie chrome bury the article.
- The same page is wanted once as markdown and once as plain text.
- Fetching, login, and length limits belong to the caller, not to a second HTTP stack.

## Primary Goal {#primary-goal}

Standardize one conversion: pick a content root, drop chrome, and render that root.

- **Benefit:** callers share one result shape (`content`, `title`, `strategy`, `tier`).
- **Benefit:** markdown and plain text stay aligned because they render the same root.
- **Cost:** site-specific extractors and URL rewriting stay outside this package.

## Core Choice {#core-choice}

Cleanup depth is visible on the result, next to which root won.

- The first pass removes hard noise and stops when a trusted root is already enough (`cleanup=cleaned`).
- A second pass drops structural chrome only when that result is too short or too long. The result stays `cleaned` if the second pass collapses, and becomes `pruned` when it holds.
- `strategy` records which root won, so a `body` result is distinguishable from an `article` result.

## Trade-Off {#trade-off}

The package does not fetch, truncate, or resolve links.

- **Benefit:** it stays a pure function of the document.
- **Benefit:** a caller that already speaks HTTP does not grow a second client.
- **Cost:** root-relative hrefs and length limits remain the caller's problem.
