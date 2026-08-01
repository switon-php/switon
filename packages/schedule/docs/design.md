# Design Rationale {#design-rationale}

`switon/schedule` keeps recurring work inside the app model instead of splitting time-based behavior across cron, workers, and hidden lock policy.

## See also {#see-also}

- [Schedule Package](README.md) — usage, configuration, and operations
- [FAQ](faq.md#schedule-faq) — short answers for setup and common issues

---

## Core Problem {#core-problem}

Recurring work drifts when one task lives in cron, another in a worker loop, and the timing rules differ by machine.

## Primary Goal {#primary-goal}

`switon/schedule` standardizes one task registry, one app clock, and one execution path.

- tasks stay container-managed classes
- expressions stay short and wall-clock based
- optional locking stays explicit

## Trade-Off {#trade-off}

- **Benefit:** schedules stay visible in application code.
- **Benefit:** operators have one app timezone to check.
- **Cost:** this is not full crontab syntax.
- **Cost:** distributed locking is opt-in.

## Deliberate Limits {#deliberate-limits}

- no per-task timezone
- no month or weekday fields
- no automatic HTTP-server scheduling
- no implicit replica coordination without `lockTtl`
