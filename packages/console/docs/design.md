# Design Rationale {#design-rationale}
`switon/console` keeps terminal-specific mechanics out of `switon/core` and out of command authoring, so apps share one progress and styling layer instead of reinventing it per command.

## See also {#see-also}

- [Console Package](README.md) — public contract and quick start
- [FAQ](faq.md#console-faq) — common setup and behavior questions
- [CLI Package](https://github.com/switon-php/cli/blob/main/docs/README.md) — the command surface that consumes these primitives

---

## Core Problem {#core-problem}

Terminal output mixes three concerns that often drift together: in-place progress rendering, terminal width detection, and ANSI styling. When each command reimplements them inside its own action method, the result is noisy, inconsistent, and hard to test.

## Primary Goal {#primary-goal}

Expose small reusable primitives instead of a full terminal toolkit.

- **Benefit:** application code shares one progress model and one width model, regardless of whether the caller is a command, a worker, or a test.
- **Benefit:** primitives stay usable when the host application has its own `ConsoleInterface` implementation.
- **Cost:** the package does not pick application bindings; integration is explicit.

## Core Choice {#core-choice}

Three independent primitives, each owning one concern.

- `ProgressBar` owns rendering state and timing hints; auto-start keeps call sites short.
- Width comes from the `COLUMNS` environment variable and is read by the layer that renders, so no detector service is shared.
- `Colors` owns ANSI bitmask constants and stays a constants-only class.

That separation keeps each primitive easy to use directly and easy to replace when an app already has its own terminal layer.

## Trade-Off {#trade-off}

`switon/console` does not own command UX or DI wiring on its own.

- **Benefit:** the package stays reusable and unobtrusive across CLI, queue, and test contexts.
- **Cost:** a host (typically `switon/cli`) must wire the interfaces; primitives alone are not a runnable command surface.

## Deliberate Simplicity {#deliberate-simplicity}

The package prefers stable terminal behavior over a larger feature set.

- progress starts when needed and ends with a clean line break;
- width detection caches the first usable result;
- color handling stays a bitmask, not a separate theme system.

That is enough for the common terminal-output path without turning the package into a UI framework.
