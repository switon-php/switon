# Queue FAQ {#queue-faq}

Short answers for jobs, envelopes, delays, and workers.

> **Prerequisites:** [Queue Package](README.md)

## Getting Started {#getting-started}

### How do I register a job? {#register-job}

Add `#[Queued('queue-name')]` to a class that implements `Switon\Core\RunnerInterface`.

### Where does discovery look? {#discovery-sources}

By default it scans app job paths and `Composer` extra jobs under `switon.jobs`.

If a class carries multiple `#[Queued]` attributes, discovery uses the first one. Empty queue names are skipped.

### What does `QueueEnvelope::of()` fill? {#envelope-of}

It fills `body` and `headers`. Set top-level protocol fields directly on the envelope when you need them.

## Behavior {#behavior}

### Does `pull()` move delayed jobs? {#pull-delayed}

No. Delayed jobs become ready only after `migrateDue()` runs.

`pull()` uses Redis `BRPOP`; `timeout = 0` means block until a job arrives.

### What does `consume()` do? {#consume}

It resolves the runner for the envelope queue and delegates execution to the executor layer.

### What does `concurrency` in `#[Queued]` do? {#queued-concurrency}

It tells `Queue` which executor path to use for that discovered job. It does not change queue routing or worker ownership.

### What happens if I push to an unmapped queue? {#unmapped-queue}

`push()` still succeeds. The problem appears later when `consume()` cannot resolve a runner.

### Can a queue name start with `.`? {#dot-queue}

Yes. Dot-prefixed queues skip stats counters.

## Workers {#workers}

### Do I need a separate migrator process? {#separate-migrator}

Not always, but some loop must call `migrateDue()`.

### What CLI actions are available? {#cli-actions}

The package ships `queue:stats`, `queue:push`, `queue:run`, `queue:work`, and `queue:migrate-due`.
`queue:run` uses a short one-second pull window, while `queue:work` can block when `timeout` is `0`.

### What does `failed` in stats mean? {#failed-stats}

It reflects Redis state for failed items; the transport itself does not write failed entries.
