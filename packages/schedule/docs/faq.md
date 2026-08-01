# Schedule FAQ {#schedule-faq}

Short answers for setup and common schedule issues.

> **Prerequisites:** [Schedule Package](README.md)

## Getting Started {#getting-started}

### How do I add a scheduled task? {#add-task}

Create a class that implements `Switon\Core\RunnerInterface`, add `#[Scheduled(...)]`, and put it under a scanned task path.

### What is the expression format? {#expression-format}

Four fields: `second minute hour day-of-month`.
Each field accepts only `*`, a concrete integer, or `*/n`.
Lists, ranges, weekday fields, and month fields are not supported.

### Can I set a timezone per task? {#per-task-timezone}

No. Tasks use `AppInterface::timezone()`.

### How do I run tasks in production? {#run-production}

Use `schedule:run` from cron or `schedule:work` in a long-lived process.

### How do I run one task now? {#task-id-run}

Use `bash bin/console schedule:list` to find the name, then `bash bin/console schedule:task <name>`.
`schedule:task` runs immediately and does not check the expression first.

## Configuration {#configuration}

### Where do I configure discovery paths? {#configure-tasks}

Set `tasks` on `Switon\Schedule\TaskDiscoveryInterface` in `switon.yml`.

### How do I inspect the available options? {#discover-options}

Run `bash bin/console config:options 'Switon\Schedule\TaskDiscoveryInterface'`.

### Why do I need Redis if every task uses `lockTtl: 0`? {#redis-binding}

The default `Lock` still needs `redisLock` to exist.

### How do I slow down or speed up `schedule:work`? {#work-tick}

Set `tick` on `Switon\Schedule\Command\ScheduleCommand` in milliseconds.

## Operations {#operations}

### Will the HTTP server run my schedules automatically? {#http-server}

No. Run the scheduler in a separate process.

### Do matched tasks run in parallel? {#parallel}

They may when coroutine runtime is enabled.

### What events can I listen for? {#events}

`Switon\Schedule\Event\TaskStarted`, `Switon\Schedule\Event\TaskFinished`, and `Switon\Schedule\Event\TaskFailed`.
`TaskStarted` and `TaskFinished` are INFO events and include `immediate`; `TaskFailed` is ERROR and carries the throwable.

## Troubleshooting {#troubleshooting}

### Task never runs {#never-runs}

Check the expression, the app timezone, `schedule:list`, and whether the task is locked on another replica.

### Task runs on more than one server {#duplicate-runs}

Use a positive `lockTtl` and healthy Redis.

### Registration fails with a duplicate task id {#duplicate-task-id}

Rename or re-namespace one class.
The task id is derived from the class name, not from HTTP handler id rules.

### `schedule:work` exits or errors immediately {#work-fails}

Coroutine runtime support is missing.
