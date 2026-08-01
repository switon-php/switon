# Design Rationale {#design-rationale}

Beacon exists so Switon apps get a **versioned**, **app-facing** AI bootstrap layer—rules and skills delivered like normal packages, not only as loose PHP libraries.

## See also {#see-also}

- [Beacon Package](README.md) — install, fit, and boundaries
- [FAQ](faq.md#beacon-faq) — quick answers and troubleshooting

---

## What Beacon Standardizes {#what-beacon-standardizes}

- **One installable package:** app-facing AI rules and skills ship together.
- **One vendor location:** skills and standards pointers live under `vendor/switon/beacon/`.
- **One update path:** Composer upgrades distribute changes predictably.

That makes guidance easier to share and update across projects.

## Deliberate Boundaries {#deliberate-boundaries}

- **App-owned rules:** stay in the application repository and `AGENTS.md`, not in Beacon edits inside `vendor/`.
- **Framework-development skills:** stay in the separate monorepo `skills/` workflow for framework contributors.
- **Agent wiring:** the app still chooses how IDE and agents load Beacon paths.

The outcome is **consistent delivery** without forcing identical app-specific rules everywhere.
