# Design Rationale {#design-rationale}

`switon/lint` keeps one file check behind one interface, and keeps project analyzers out of the default path.

## See also {#see-also}

- [Lint Package](README.md) — usage and configuration
- [FAQ](faq.md#lint-faq) — reports and attach

---

## Core Problem {#core-problem}

A write that shells out to a different linter per language soon disagrees on paths, exit codes, and how a missing tool is reported.

## Primary Goal {#primary-goal}

Return the same diagnostic shape for every language, and keep the slow analyzer off unless the caller asks.

- **Benefit:** syntax feedback is available without installing PHPStan, ESLint, or shellcheck.
- **Cost:** a deep check is only as good as the tool discovered beside that file.

## Core Choice {#core-choice}

The extension selects the driver. Each driver always runs its syntax tool. `$deep = true` may add one analyzer when the project already has it. `attach()` stays on the syntax path so a checker crash cannot fail the write.
