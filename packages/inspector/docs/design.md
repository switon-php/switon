# Design Rationale {#design-rationale}

`switon/inspector` keeps request diagnostics as one request-scoped surface so packages can add diagnostics without inventing their own debug channels.

## See also {#see-also}

- [Inspector Package](README.md) — usage, configuration, and operating decisions
- [FAQ](faq.md#inspector-faq) — short answers and troubleshooting

---

## Core Problem {#core-problem}

Request diagnostics tend to drift into separate ad hoc paths.

- one package adds its own debug URL
- another hard-codes a header
- a third stores state on a shared service
- tabs become package-specific and hard to combine

That makes inspection inconsistent across the app.

## Primary Goal {#primary-goal}

Inspector standardizes one request-scoped link, one stored payload, and one rendering path.

- **Benefit:** request inspection stays easy to share across packages.
- **Benefit:** the same data can serve browser, terminal, and tooling use cases.
- **Cost:** Inspector stays a controlled debugging surface, not a general observability platform.

## Core Choices {#core-choices}

- **Lifecycle-generated link:** request events create the link before the payload is written.
- **Metadata-driven collectors:** packages contribute panels through metadata; the app can override or disable keys.
- **Separate storage and rendering:** the stored payload is independent from how it is viewed.
- **Context-scoped state:** collectors keep mutable request data in context objects instead of shared singleton properties, while Inspector itself stays request-scoped.

## Trade-Offs {#trade-offs}

- `ttl > 0` means Redis, `ttl = 0` means filesystem.
- Not every installed package contributes a tab.
- Defaults stay development-first, so production exposure stays explicit.
