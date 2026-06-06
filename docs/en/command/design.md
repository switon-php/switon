# Design Rationale {#design-rationale}

`switon/command` keeps command discovery and command metadata in one shared layer so CLI help and AI tooling read the same shape.

## See also {#see-also}

- [Command Package](README.md) — usage, metadata, and the public contract
- [FAQ](faq.md#command-faq) — common questions and troubleshooting

## Core Problem {#core-problem}

Command handling tends to drift in three directions:

- one consumer invents its own discovery rules
- another rewrites help text from scratch
- a third adds separate AI or tooling annotations

Switon keeps those concerns on one metadata model so command authors only describe the command once.

## Primary Goal {#primary-goal}

The package standardizes command names, visibility, summaries, and aliases before it tries to become a runner.

- **Benefit:** CLI and Beacon can share one command definition.
- **Benefit:** help text and tooling metadata stay aligned.
- **Cost:** execution, scheduling, and transport stay outside this package.

## Core Choice {#core-choice}

Class names define the command family, `*Action()` methods define the entries, and attributes add overlays.

- discovery builds the command registry
- inspection reads summaries, actions, and parameters
- `#[Hidden]`, `#[Tool]`, and `#[ShortOption]` stay small and scoped to the declaration they decorate

That keeps the model easy to scan without turning the package into a full command framework.

## Trade-Off {#trade-off}

Switon does not make this package own invocation, shell UX, or a generated command catalog.

- **Benefit:** the metadata layer stays reusable across consumers.
- **Benefit:** the same class can feed CLI help and AI tooling.
- **Cost:** higher-level command behavior still belongs in CLI or related packages.

## Deliberate Simplicity {#deliberate-simplicity}

The package stops at the stable authoring surface.

- command discovery returns a cached map
- action metadata comes from reflection and PHPDoc
- tool descriptions stay short by design
- hidden entries remain runnable

That is enough for the common command authoring path without making the package own the whole operator experience.
