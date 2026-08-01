# Design Rationale {#design-rationale}
`switon/log` keeps logging small, stateless, and easy to use across different runtime forms.

## See also {#see-also}

- [Log Package](README.md) — usage, configuration, and the public contract
- [FAQ](faq.md#log-faq) — common questions and troubleshooting

---

## Core Problem {#core-problem}

Logging is one of the first places where app structure drifts.

- One service invents its own channel naming scheme.
- Another service concatenates strings and loses structured fields.
- A third service mixes routing policy into business code.
- Long-running runtimes leak request-scoped state into shared loggers.

The framework needs one logging boundary that stays readable in application code and predictable for operators.

## Primary Goal {#primary-goal}

`switon/log` standardizes one PSR-3 contract first, then layers categories and appenders on top.

- **Benefit:** application code keeps one stable logging style.
- **Benefit:** behavior is easier to follow when you switch between HTTP, CLI, workers, and tests.
- **Cost:** advanced per-destination routing stays outside the default model.

## Core Choice {#core-choice}

The package treats each log call as a self-contained operation.

- **Category resolution** runs on each log call.
- **Accepted entries** become a `LogEntry`.
- **Prepared entries** dispatch `LoggerLogged` so listeners can add fields to `LogEntry::extra` before appenders run.
- **Appenders** use fan-out: each configured appender receives the same `LogEntry`.

That keeps the logging path short, keeps call sites free of destination details, and keeps extension points in listeners and appenders.

## Trade-Off {#trade-off}

The package does not model handler trees or bubbling rules.

- **Benefit:** output behavior is simple to predict.
- **Benefit:** diagnostics stay smaller.
- **Cost:** routing differs from a classic handler tree: you cannot send one category to only one appender by default.

## Deliberate Simplicity {#deliberate-simplicity}

Structured data is part of the contract, not a side effect of formatting.

- **Placeholders** stay in the message.
- **Non-placeholder context** stays in `extra`.
- **Formatters** (`FormatterInterface`) decide how the entry is rendered.
- **Cross-cutting fields** are added in listeners before appenders run.

That keeps the package useful for both human-readable logs and structured observability.
