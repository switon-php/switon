# Executor FAQ {#executor-faq}
Short answers for task ids, runtime mode, and error handling.

> **Prerequisites:** [Executor Package](README.md)

## Getting Started {#getting-started}

### What does `invoke()` do? {#invoke-sync}

It runs one task in the current flow and rethrows runner failures.

### When should I use `submit()`, `invokeAll()`, or `invokeAny()`? {#which-method}

Use `submit()` when the caller does not need a result in the same flow, `invokeAll()` when every outcome matters, and
`invokeAny()` when the first success is enough.

### What can I use as a task id? {#task-id}

Use any container id that resolves to a `RunnerInterface`. A runner FQCN is usually the clearest default. Add
`:suffix` only when one runner needs separate `submit()` buckets.

Only the first `:` splits the runner id; later `:` characters stay in the suffix.

### Does `submit()` always run in the background? {#submit-background}

No. It becomes background work only in coroutine-enabled runtime. In serial runtime it runs in the current flow.

`invokeAll()` and `invokeAny()` do not use the `submit()` gate.

### Does `invokeAny()` cancel the other tasks? {#invoke-any-cancel}

No. It returns on the first success, but already started coroutine tasks keep running.

It also returns the first successful result, not the first task in input order.

## Runtime {#runtime}

### What does the third `submit()` argument do? {#limit-concurrency}

It caps in-flight coroutine submissions for that task string. Values less than `1` disable the gate. It applies only in coroutine-enabled runtime.

### How do I read batch failures? {#batch-failures}

Use `invokeAll()` and inspect each `FutureInterface`. Failed tasks keep their throwable on `error()`.

### Does `invokeAll()` stop on the first failure? {#invoke-all-stop}

No. It keeps one future per input and records each failure separately.

### What happens when no task succeeds? {#invoke-any-null}

`invokeAny()` returns `null`.

### What happens when scheduling fails? {#scheduling-failure}

`submit()` throws `TaskSchedulingException`. `invokeAll()` records the failure on the matching future, and
`invokeAny()` keeps looking for another success.

`RunnerFinished` still fires when a runner throws, and the exception is rethrown to the caller of `invoke()`.

## Troubleshooting {#troubleshooting}

### Why am I getting `TaskRunnerException`? {#task-runner-exception}

The resolved service does not implement `Switon\Core\RunnerInterface`.

### Why am I getting `InvalidTaskException`? {#invalid-task-exception}

The task id is empty, or the prefix before `:` is empty.
